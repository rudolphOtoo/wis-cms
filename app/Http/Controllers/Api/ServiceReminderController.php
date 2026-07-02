<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\ServiceReminderLog;
use App\Models\ServiceReminderSettings;
use App\Models\ServiceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-service-type SMS reminder management: list all configured
 * reminders for the branch, edit each one, preview templates, see
 * what's next on the schedule, and audit past sends.
 *
 * The scheduled command (SendServiceReminders) does the actual
 * sending hourly; this controller is purely admin UI.
 */
class ServiceReminderController extends Controller
{
    /**
     * GET /api/reminders/settings
     * Returns ALL reminder settings rows for the current branch, one
     * per configured service type. Service types without a row appear
     * with `configured: false` so the UI can offer "Configure" on them.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;

        $existing = ServiceReminderSettings::query()
            ->where('branch_id', $branchId)
            ->with('serviceType')
            ->get()
            ->keyBy('service_type_id');

        $allTypes = ServiceType::where('is_active', true)->get();

        $rows = $allTypes->map(function ($type) use ($existing) {
            $settings = $existing->get($type->id);

            return [
                'service_type_id' => $type->id,
                'service_type_name' => $type->name,
                'service_type_slug' => $type->slug,
                'configured' => $settings !== null,
                'id' => $settings?->id,
                'template' => $settings?->template,
                'send_day_of_week' => $settings?->send_day_of_week,
                'send_day_label' => $settings?->dayName(),
                'send_hour' => $settings?->send_hour,
                'send_hour_label' => $settings?->hourLabel(),
                'service_hour' => $settings?->service_hour,
                'service_minute' => $settings?->service_minute,
                'service_time_label' => $settings?->serviceTimeLabel(),
                'is_active' => $settings?->is_active ?? false,
                'default_template_suggestion' => ServiceReminderSettings::defaultTemplateFor($type->slug),
            ];
        });

        return response()->json([
            'data' => $rows->values(),
            'placeholders' => [
                '{first_name}' => "Member's first name",
                '{last_name}' => "Member's last name",
                '{full_name}' => 'Full name',
                '{service_name}' => 'Service type name',
                '{service_date}' => 'Date of the service (e.g. "Sunday 14 June")',
                '{service_time}' => 'Time the service starts (e.g. "9:00 AM")',
                '{church_name}' => 'Branch / church name',
            ],
        ]);
    }

    /**
     * PUT /api/reminders/settings/{service_type_id}
     * Create-or-update the reminder settings for one service type.
     * If no row exists for this branch + service_type yet, one is
     * created; otherwise updated in place.
     */
    public function upsert(Request $request, string $serviceTypeId): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', 'max:500', function ($attr, $value, $fail) {
                if (! preg_match('/\{(first_name|last_name|full_name)\}/', $value)) {
                    $fail('Template must include at least one of: {first_name}, {last_name}, {full_name}.');
                }
            }],
            'send_day_of_week' => ['required', 'integer', 'between:0,6'],
            'send_hour' => ['required', 'integer', 'between:0,23'],
            'service_hour' => ['required', 'integer', 'between:0,23'],
            'service_minute' => ['required', 'integer', Rule::in([0, 15, 30, 45])],
            'is_active' => ['required', 'boolean'],
        ]);

        $branchId = $request->user()->branch_id;

        // Make sure the service_type exists (we don't want to allow
        // configuring reminders for a deleted service type).
        $type = ServiceType::findOrFail($serviceTypeId);

        $settings = ServiceReminderSettings::updateOrCreate(
            [
                'branch_id' => $branchId,
                'service_type_id' => $type->id,
            ],
            $validated
        );

        activity()->causedBy($request->user())
            ->performedOn($settings)
            ->log("Updated {$type->name} reminder settings");

        return response()->json([
            'message' => "{$type->name} reminder settings saved.",
            'data' => $settings->fresh()->load('serviceType'),
        ]);
    }

    /**
     * POST /api/reminders/preview
     * Render a (possibly unsaved) template against a sample member.
     * Used by the UI to give immediate feedback as the admin types.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string', 'max:500'],
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'service_hour' => ['nullable', 'integer', 'between:0,23'],
            'service_minute' => ['nullable', 'integer', Rule::in([0, 15, 30, 45])],
        ]);

        $branchId = $request->user()->branch_id;
        $type = ServiceType::findOrFail($validated['service_type_id']);

        // Build an in-memory settings instance without persisting.
        $preview = new ServiceReminderSettings([
            'branch_id' => $branchId,
            'service_type_id' => $type->id,
            'template' => $validated['template'],
            'service_hour' => $validated['service_hour'] ?? 9,
            'service_minute' => $validated['service_minute'] ?? 0,
        ]);
        $preview->setRelation('serviceType', $type);
        $preview->setRelation('branch', $request->user()->branch);

        // Use the first active member for realism; fall back to a
        // synthetic member if the branch has none.
        $sample = Member::where('branch_id', $branchId)
            ->where('status', 'active')
            ->first();

        if (! $sample) {
            $sample = new Member([
                'first_name' => 'Sample',
                'last_name' => 'Member',
            ]);
        }

        $body = $preview->render($sample);
        $charCount = mb_strlen($body);
        $smsSegments = (int) ceil($charCount / 160);

        return response()->json([
            'data' => [
                'rendered' => $body,
                'char_count' => $charCount,
                'sms_segments' => $smsSegments,
                'sample_member_name' => trim($sample->first_name.' '.$sample->last_name),
            ],
        ]);
    }

    /**
     * GET /api/reminders/log?days=30&status=sent&service_type_id=...
     * Audit log of past sends with optional filters.
     */
    public function log(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'between:1,365'],
            'status' => ['nullable', 'string', Rule::in(['sent', 'no_phone', 'failed'])],
            'service_type_id' => ['nullable', 'uuid', 'exists:service_types,id'],
        ]);

        $days = $validated['days'] ?? 30;
        $branchId = $request->user()->branch_id;

        $logs = ServiceReminderLog::query()
            ->where('branch_id', $branchId)
            ->where('sent_at', '>=', now()->subDays($days))
            ->when(! empty($validated['status']), fn ($q) => $q->status($validated['status']))
            ->when(! empty($validated['service_type_id']),
                fn ($q) => $q->where('service_type_id', $validated['service_type_id']))
            ->with(['member:id,first_name,last_name,phone', 'serviceType:id,name,slug'])
            ->orderByDesc('sent_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'member_id' => $log->member_id,
                'member_name' => $log->member ? trim("{$log->member->first_name} {$log->member->last_name}") : '(deleted)',
                'service_type' => $log->serviceType?->name,
                'sent_at' => $log->sent_at,
                'intended_service_date' => $log->intended_service_date?->toDateString(),
                'status' => $log->status,
                'phone_used' => $log->phone_used,
                'message_body' => $log->message_body,
                'error_message' => $log->error_message,
            ]),
            'meta' => [
                'days' => $days,
                'total' => $logs->count(),
            ],
        ]);
    }

    /**
     * GET /api/reminders/upcoming
     * Show the next 7 days of scheduled reminders so admins can see
     * "what's about to fire."
     */
    public function upcoming(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;

        $settings = ServiceReminderSettings::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with('serviceType')
            ->get();

        if ($settings->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $upcoming = [];
        $cursor = now();

        // Walk forward day-by-day; for each day, check each settings row.
        for ($i = 0; $i < 7; $i++) {
            $day = $cursor->copy()->addDays($i)->startOfDay();

            foreach ($settings as $s) {
                if ($s->send_day_of_week !== $day->dayOfWeek) {
                    continue;
                }
                $fireMoment = $day->copy()->setHour($s->send_hour);

                // Don't show ones already past today.
                if ($fireMoment->isPast()) {
                    continue;
                }

                $upcoming[] = [
                    'service_type' => $s->serviceType?->name,
                    'send_day' => $s->dayName(),
                    'send_time' => $s->hourLabel(),
                    'fires_at' => $fireMoment->toIso8601String(),
                    'service_time' => $s->serviceTimeLabel(),
                ];
            }
        }

        return response()->json(['data' => $upcoming]);
    }
}
