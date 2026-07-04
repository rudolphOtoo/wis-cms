<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\NotifyAdminOfApprovalJob;
use App\Jobs\SendMemberWelcomeSmsJob;
use App\Models\Cell;
use App\Models\Member;
use App\Models\MemberSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-side management of the member_submissions review queue.
 *
 * Submissions arrive via the public webhook (untrusted input) and
 * accumulate as 'pending'. An authorised admin reviews each one:
 *   - approve: promote to Member, optionally assign a cell
 *   - reject:  mark rejected with optional notes
 *
 * Approved members become eligible for SMS dispatch, cell assignment,
 * reports, and all other standard member workflows.
 */
class MemberSubmissionController extends Controller
{
    /**
     * GET /api/submissions
     *
     * List submissions filtered by status (defaults to 'pending').
     * Each row includes a duplicate-detection hint: any existing Member
     * in the same branch whose phone matches the submission.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['pending', 'approved', 'rejected', 'all'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $status = $validated['status'] ?? 'pending';
        $branchId = $request->user()->branch_id;

        $query = MemberSubmission::query()
            ->where('branch_id', $branchId)
            ->orderByDesc('submitted_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $paginated = $query->paginate(15);

        // Build a duplicate-detection map for this page in a single query.
        $phones = $paginated->pluck('phone')->unique()->values();
        $existingMembers = Member::query()
            ->where('branch_id', $branchId)
            ->whereIn('phone', $phones)
            ->get(['id', 'first_name', 'last_name', 'phone'])
            ->keyBy('phone');

        $data = $paginated->getCollection()->map(
            function (MemberSubmission $sub) use ($existingMembers): array {
                $existing = $existingMembers->get($sub->phone);

                return [
                    'id' => $sub->id,
                    'first_name' => $sub->first_name,
                    'last_name' => $sub->last_name,
                    'full_name' => $sub->full_name,
                    'phone' => $sub->phone,
                    'email' => $sub->email,
                    'gender' => $sub->gender,
                    'date_of_birth' => $sub->date_of_birth?->toDateString(),
                    'address' => $sub->address,
                    'occupation' => $sub->occupation,
                    'marital_status' => $sub->marital_status,
                    'cell_name_submitted' => $sub->cell_name,
                    'status' => $sub->status,
                    'source' => $sub->source,
                    'submitted_at' => $sub->submitted_at,
                    'reviewed_at' => $sub->reviewed_at,
                    'duplicate_member' => $existing ? [
                        'id' => $existing->id,
                        'name' => trim("{$existing->first_name} {$existing->last_name}"),
                        'phone' => $existing->phone,
                    ] : null,
                ];
            }
        );

        return response()->json([
            'data' => $data,
            'meta' => [
                'status_filter' => $status,
                'total' => $paginated->total(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'pending_count' => MemberSubmission::query()
                    ->where('branch_id', $branchId)
                    ->where('status', MemberSubmission::STATUS_PENDING)
                    ->count(),
            ],
        ]);
    }

    /**
     * GET /api/submissions/{id}
     *
     * Full detail for a single submission, including raw payload,
     * duplicate-member hint, and the list of active cells available
     * for assignment during approval.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $submission = MemberSubmission::where('branch_id', $request->user()->branch_id)
            ->where('id', $id)
            ->with(['reviewedBy:id,name', 'approvedMember:id,first_name,last_name', 'approvedMember.cell'])
            ->firstOrFail();

        $existing = $submission->existingMemberWithSamePhone();

        $cells = Cell::query()
            ->where('branch_id', $request->user()->branch_id)
            ->where('is_active', true)
            ->where('name', '!=', 'Children Ministry')  // adult members cannot join this cell
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'data' => [
                'id' => $submission->id,
                'first_name' => $submission->first_name,
                'last_name' => $submission->last_name,
                'full_name' => $submission->full_name,
                'phone' => $submission->phone,
                'email' => $submission->email,
                'gender' => $submission->gender,
                'date_of_birth' => $submission->date_of_birth?->toDateString(),
                'address' => $submission->address,
                'occupation' => $submission->occupation,
                'marital_status' => $submission->marital_status,
                'cell_name_submitted' => $submission->cell_name,
                'status' => $submission->status,
                'source' => $submission->source,
                'submitted_at' => $submission->submitted_at,
                'reviewed_at' => $submission->reviewed_at,
                'reviewed_by' => $submission->reviewedBy
                    ? ['id' => $submission->reviewedBy->id, 'name' => $submission->reviewedBy->name]
                    : null,
                'review_notes' => $submission->review_notes,
                'approved_member' => $submission->approvedMember
                    ? [
                        'id' => $submission->approvedMember->id,
                        'name' => trim("{$submission->approvedMember->first_name} {$submission->approvedMember->last_name}"),
                    ]
                    : null,
                'source_ip' => $submission->source_ip,
                'raw_payload' => $submission->raw_payload,
                'duplicate_member' => $existing ? [
                    'id' => $existing->id,
                    'name' => trim("{$existing->first_name} {$existing->last_name}"),
                    'phone' => $existing->phone,
                    'status' => $existing->status,
                ] : null,
            ],
            'cells' => $cells,
        ]);
    }

    /**
     * POST /api/submissions/{id}/approve
     *
     * Promotes a pending submission to a real Member record.
     *
     * Conflict guard: if an ACTIVE Member with the same phone already
     * exists in the branch, this endpoint returns HTTP 409 with the
     * conflicting record. The admin client must re-submit with
     * `force_overwrite: true` to proceed. This prevents silent data
     * overwrites on existing congregants.
     *
     * On success, two background jobs are dispatched:
     *   - NotifyAdminOfApprovalJob: SMS alert to all branch admins.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'cell_id' => [
                'nullable', 'uuid', 'exists:cells,id',
                function (string $attr, mixed $value, \Closure $fail): void {
                    if ($value && Cell::where('id', $value)->where('name', 'Children Ministry')->exists()) {
                        $fail('The Children Ministry cell is for children only. Adult members cannot be assigned here.');
                    }
                },
            ],
            'notes' => ['nullable', 'string', 'max:500'],
            'force_overwrite' => ['nullable', 'boolean'],
        ]);

        $submission = MemberSubmission::where('branch_id', $request->user()->branch_id)
            ->where('id', $id)
            ->firstOrFail();

        if ($submission->status !== MemberSubmission::STATUS_PENDING) {
            return response()->json([
                'message' => "Submission is already {$submission->status} and cannot be approved again.",
            ], 422);
        }

        // ── Conflict guard ────────────────────────────────────────────────────
        // Detect an active Member whose phone matches this submission. An admin
        // must explicitly acknowledge the overwrite by sending force_overwrite=true.
        // This surfaces data collisions rather than silently clobbering a real member.
        $existingMember = $submission->existingMemberWithSamePhone();

        if ($existingMember && $existingMember->status === 'active' && ! ($validated['force_overwrite'] ?? false)) {
            return response()->json([
                'message' => 'An active member with this phone number already exists. '
                    .'Send force_overwrite: true to update their record with this submission\'s data.',
                'existing_member' => [
                    'id' => $existingMember->id,
                    'name' => $existingMember->full_name,
                    'phone' => $existingMember->phone,
                    'status' => $existingMember->status,
                ],
                'requires_confirmation' => true,
            ], 409);
        }

        // ── Promote (runs inside DB::transaction) ─────────────────────────────
        $member = $submission->promote(
            $request->user(),
            $validated['cell_id'] ?? null,
            $validated['notes'] ?? null,
        );

        // ── Audit log ─────────────────────────────────────────────────────────
        activity()
            ->causedBy($request->user())
            ->performedOn($submission)
            ->log("Approved member submission for {$submission->full_name}");

        // ── Async notifications ───────────────────────────────────────────────
        // Both jobs are fire-and-forget. Failures are retried by the queue
        // worker and do not affect the HTTP response to the admin.
        NotifyAdminOfApprovalJob::dispatch($submission->id, $member->id);
        SendMemberWelcomeSmsJob::dispatch($member->id, $member->cell?->name);

        return response()->json([
            'message' => 'Submission approved and promoted to member.',
            'data' => [
                'submission_id' => $submission->id,
                'member' => [
                    'id' => $member->id,
                    'name' => trim("{$member->first_name} {$member->last_name}"),
                    'phone' => $member->phone,
                    'cell_id' => $member->cell_id,
                ],
            ],
        ]);
    }

    /**
     * POST /api/submissions/{id}/reject
     *
     * Mark a pending submission as rejected with optional admin notes.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $submission = MemberSubmission::where('branch_id', $request->user()->branch_id)
            ->where('id', $id)
            ->firstOrFail();

        if ($submission->status !== MemberSubmission::STATUS_PENDING) {
            return response()->json([
                'message' => "Submission is already {$submission->status} and cannot be rejected again.",
            ], 422);
        }

        $submission->reject($request->user(), $validated['notes'] ?? null);

        activity()
            ->causedBy($request->user())
            ->performedOn($submission)
            ->log("Rejected member submission for {$submission->full_name}");

        return response()->json([
            'message' => 'Submission rejected.',
            'data' => [
                'submission_id' => $submission->id,
                'status' => $submission->status,
            ],
        ]);
    }
}
