<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BirthdayMessageLog;
use App\Models\BirthdayMessageSettings;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages the per-branch birthday-greeting system: editable template,
 * a preview endpoint, the upcoming-this-week list (with cell-leader
 * scoping), and the audit log of past sends.
 *
 * The scheduled command (SendBirthdayGreetings) does the actual sending
 * daily at 07:00 Africa/Accra; this controller is purely admin UI.
 */
class BirthdayController extends Controller
{
    /**
     * GET /api/birthdays/settings
     * Returns the current branch's template, sender preference, and
     * active toggle. Creates a default row on first access.
     */
    public function showSettings(Request $request): JsonResponse
    {
        $settings = BirthdayMessageSettings::forBranch($request->user()->branch_id);

        return response()->json([
            'data' => [
                'id' => $settings->id,
                'template' => $settings->template,
                'is_active' => $settings->is_active,
                'sender_id' => $settings->sender_id,
                'updated_at' => $settings->updated_at,
            ],
            'placeholders' => [
                '{first_name}' => 'Member\'s first name',
                '{last_name}' => 'Member\'s last name',
                '{full_name}' => 'Full name',
                '{church_name}' => 'Church name from branch',
            ],
        ]);
    }

    /**
     * PUT /api/birthdays/settings
     * Updates template and/or active toggle. Template must contain at
     * least one name placeholder so messages stay personal.
     */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', 'max:500', function ($attr, $value, $fail) {
                if (! preg_match('/\{(first_name|last_name|full_name)\}/', $value)) {
                    $fail('Template must include at least one of: {first_name}, {last_name}, {full_name}.');
                }
            }],
            'is_active' => ['required', 'boolean'],
        ]);

        $settings = BirthdayMessageSettings::forBranch($request->user()->branch_id);
        $settings->update($validated);

        activity()->causedBy($request->user())
            ->performedOn($settings)
            ->log('Updated birthday message settings');

        return response()->json([
            'message' => 'Birthday settings updated successfully.',
            'data' => $settings->fresh(),
        ]);
    }

    /**
     * POST /api/birthdays/preview
     * Renders the (possibly unsaved) template with a sample member.
     * Helpful for admins testing a new template before saving.
     *
     * Body: { template: string }   — preview this string
     *       (omit to preview the saved template)
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = BirthdayMessageSettings::forBranch($request->user()->branch_id);

        // If the caller provided a template, render that. Otherwise
        // render the saved one. Lets the UI preview unsaved edits.
        $template = $validated['template'] ?? $settings->template;

        // Use a real member from the branch if possible, so the preview
        // looks like what members will actually receive. Fall back to
        // a fixture if the branch has no active members yet.
        $sampleMember = Member::query()
            ->where('status', 'active')
            ->whereNotNull('first_name')
            ->first();

        $churchName = config('church.name', 'Wesleyan International Society');

        if ($sampleMember) {
            $rendered = strtr($template, [
                '{first_name}' => $sampleMember->first_name,
                '{last_name}' => $sampleMember->last_name,
                '{full_name}' => trim("{$sampleMember->first_name} {$sampleMember->last_name}"),
                '{church_name}' => $churchName,
            ]);
            $sampleSource = 'real_member';
        } else {
            $rendered = strtr($template, [
                '{first_name}' => 'Ama',
                '{last_name}' => 'Mensah',
                '{full_name}' => 'Ama Mensah',
                '{church_name}' => $churchName,
            ]);
            $sampleSource = 'fixture';
        }

        // mNotify (and most SMS providers) bill per 160-char segment.
        // Show segments count so admin can see if their template will
        // cost extra.
        $length = strlen($rendered);
        $segments = $length === 0 ? 0 : (int) ceil($length / 160);

        return response()->json([
            'data' => [
                'rendered_message' => $rendered,
                'character_count' => $length,
                'sms_segments' => $segments,
                'sample_source' => $sampleSource,
            ],
        ]);
    }

    /**
     * GET /api/birthdays/upcoming?days=7
     * Lists members with birthdays in the next N days (1-31).
     * Cell leaders see only their cell's members; admins see all.
     */
    public function upcoming(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:31'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $days = $validated['days'] ?? 7;
        $user = $request->user();

        $today = Carbon::today('Africa/Accra');
        $endDate = $today->copy()->addDays($days);

        $todayMD = $today->format('m-d');
        $endMD = $endDate->format('m-d');

        $query = Member::query()
            ->where('status', 'active')
            ->whereNotNull('date_of_birth');

        // Scope by cell leader if applicable
        $isAdmin = $user->hasAnyRole(['super_admin', 'pastor', 'secretary']);
        if (! $isAdmin && $user->hasRole('cell_leader')) {
            $query->whereHas('cell', function ($q) use ($user) {
                $q->where('leader_user_id', $user->id);
            });
        }

        // Filter birthdays by month-day range using SQL, handling
        // year-crossing windows (e.g. Dec 28 → Jan 4).
        if ($todayMD <= $endMD) {
            $query->whereRaw("TO_CHAR(date_of_birth, 'MM-DD') BETWEEN ? AND ?", [$todayMD, $endMD]);
        } else {
            $query->whereRaw("TO_CHAR(date_of_birth, 'MM-DD') >= ? OR TO_CHAR(date_of_birth, 'MM-DD') <= ?", [$todayMD, $endMD]);
        }

        $members = $query
            ->with('cell:id,name')
            ->orderByRaw('EXTRACT(DOY FROM date_of_birth)')
            ->get();

        $upcoming = $members
            ->map(function (Member $m) use ($today) {
                $dob = Carbon::parse($m->date_of_birth);
                $thisYear = $dob->copy()->year($today->year);
                if ($thisYear->lt($today)) {
                    $thisYear->addYear();
                }
                $daysAway = (int) $today->diffInDays($thisYear, false);

                return [
                    'id' => $m->id,
                    'first_name' => $m->first_name,
                    'last_name' => $m->last_name,
                    'full_name' => trim("{$m->first_name} {$m->last_name}"),
                    'date_of_birth' => $m->date_of_birth->toDateString(),
                    'birthday_this_year' => $thisYear->toDateString(),
                    'days_away' => $daysAway,
                    'phone' => $m->phone,
                    'has_phone' => ! empty($m->phone),
                    'cell' => $m->cell ? ['id' => $m->cell->id, 'name' => $m->cell->name] : null,
                    'age_turning' => $thisYear->year - $dob->year,
                ];
            })
            ->filter(fn ($m) => $m['days_away'] >= 0 && $m['days_away'] <= $days)
            ->sortBy('days_away')
            ->values();

        return response()->json([
            'data' => $upcoming,
            'meta' => [
                'days_window' => $days,
                'scope' => $isAdmin ? 'all_branch' : 'cell_leader_only',
                'count' => $upcoming->count(),
            ],
        ]);
    }

    /**
     * GET /api/birthdays/log?days=30&status=sent
     * Audit trail of past sends. Filterable by status.
     * Admins only — cell leaders don't get this endpoint.
     */
    public function log(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'status' => ['nullable', 'in:sent,no_phone,failed'],
        ]);

        $days = $validated['days'] ?? 30;
        $cutoff = Carbon::today('Africa/Accra')->subDays($days);

        $query = BirthdayMessageLog::query()
            ->with('member:id,first_name,last_name')
            ->where('sent_at', '>=', $cutoff)
            ->orderByDesc('sent_at');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $entries = $query->limit(500)->get();

        $summary = [
            'sent' => 0,
            'no_phone' => 0,
            'failed' => 0,
        ];
        foreach ($entries as $entry) {
            if (isset($summary[$entry->status])) {
                $summary[$entry->status]++;
            }
        }

        return response()->json([
            'data' => $entries->map(fn ($e) => [
                'id' => $e->id,
                'sent_at' => $e->sent_at->toIso8601String(),
                'status' => $e->status,
                'phone_used' => $e->phone_used,
                'message_body' => $e->message_body,
                'error_message' => $e->error_message,
                'member' => $e->member ? [
                    'id' => $e->member->id,
                    'name' => trim("{$e->member->first_name} {$e->member->last_name}"),
                ] : null,
            ]),
            'meta' => [
                'days_window' => $days,
                'summary' => $summary,
                'total_in_window' => $entries->count(),
            ],
        ]);
    }
}
