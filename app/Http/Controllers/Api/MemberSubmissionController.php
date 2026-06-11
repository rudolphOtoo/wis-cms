<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cell;
use App\Models\Member;
use App\Models\MemberSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-side management of member_submissions queue.
 *
 * Submissions arrive via the public webhook (untrusted input)
 * and accumulate as 'pending'. Admin reviews each:
 *   - approve: promote to Member, optionally assign a cell
 *   - reject:  mark rejected, optionally with notes
 *
 * Approved members are eligible for SMS dispatch and all the
 * standard member workflows.
 */
class MemberSubmissionController extends Controller
{
    /**
     * GET /api/submissions
     * List submissions filtered by status (default pending).
     * Each row includes a duplicate-detection hint: any existing
     * member with the same phone in the same branch.
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

        // Build duplicate-detection map: phone → existing Member info.
        // Single query keyed on the phones in this page of submissions.
        $phones = $paginated->pluck('phone')->unique()->values();
        $existingMembers = Member::query()
            ->where('branch_id', $branchId)
            ->whereIn('phone', $phones)
            ->get(['id', 'first_name', 'last_name', 'phone'])
            ->keyBy('phone');

        $data = $paginated->getCollection()->map(function ($sub) use ($existingMembers) {
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
                'submitted_at' => $sub->submitted_at,
                'reviewed_at' => $sub->reviewed_at,
                'duplicate_member' => $existing ? [
                    'id' => $existing->id,
                    'name' => trim("{$existing->first_name} {$existing->last_name}"),
                    'phone' => $existing->phone,
                ] : null,
            ];
        });

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
                    ->where('status', 'pending')
                    ->count(),
            ],
        ]);
    }

    /**
     * GET /api/submissions/{id}
     * Full detail of one submission including raw payload + cells
     * available for assignment.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $submission = MemberSubmission::where('branch_id', $request->user()->branch_id)
            ->where('id', $id)
            ->with(['reviewedBy:id,name', 'approvedMember:id,first_name,last_name'])
            ->firstOrFail();

        $existing = $submission->existingMemberWithSamePhone();

        $cells = Cell::query()
            ->where('branch_id', $request->user()->branch_id)
            ->where('is_active', true)
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
     * Promote to a Member. Optionally assign a cell + add notes.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'cell_id' => ['nullable', 'uuid', 'exists:cells,id'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $submission = MemberSubmission::where('branch_id', $request->user()->branch_id)
            ->where('id', $id)
            ->firstOrFail();

        if ($submission->status !== MemberSubmission::STATUS_PENDING) {
            return response()->json([
                'message' => "Submission already {$submission->status}.",
            ], 422);
        }

        $member = $submission->promote(
            $request->user(),
            $validated['cell_id'] ?? null,
            $validated['notes'] ?? null,
        );

        activity()->causedBy($request->user())
            ->performedOn($submission)
            ->log("Approved member submission for {$submission->full_name}");

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
     * Mark rejected with optional notes.
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
                'message' => "Submission already {$submission->status}.",
            ], 422);
        }

        $submission->reject($request->user(), $validated['notes'] ?? null);

        activity()->causedBy($request->user())
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
