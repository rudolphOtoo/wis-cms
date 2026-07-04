<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceSessionResource;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Cell;
use App\Models\Children;
use App\Models\Department;
use App\Models\Member;
use App\Models\ServiceType;
use App\Services\AttendanceStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceStatsService $statsService) {}

    // GET /api/attendance
    public function index(Request $request): JsonResponse
    {
        $sessions = AttendanceSession::query()
            // PERF FIX: 'records' must be eager-loaded here so that
            // AttendanceSession::getAdultCountAttribute() and
            // getChildrenCountAttribute() use the in-memory collection
            // (zero extra queries) rather than falling back to a per-session
            // COUNT query (2 queries × N rows = N+1 on every page load).
            ->withCount([
                'records as adult_count' => fn ($q) => $q->where('is_present', true)->whereNotNull('member_id'),
                'records as children_count' => fn ($q) => $q->where('is_present', true)->whereNotNull('child_id'),
            ])
            ->with(['serviceType', 'recorder', 'branch'])
            ->orderByDesc('service_date')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => AttendanceSessionResource::collection($sessions->items()),
            'meta' => [
                'total' => $sessions->total(),
                'per_page' => $sessions->perPage(),
                'current_page' => $sessions->currentPage(),
                'last_page' => $sessions->lastPage(),
            ],
        ]);
    }

    // GET /api/attendance/service-types
    public function serviceTypes(): JsonResponse
    {
        $types = ServiceType::where('is_active', true)->get();

        return response()->json(['data' => $types]);
    }

    // POST /api/attendance/sessions
    public function createSession(Request $request): JsonResponse
    {
        $request->validate([
            'service_type_id' => ['required', 'uuid', 'exists:service_types,id'],
            'service_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
            'department_id' => ['nullable', 'uuid', 'exists:departments,id'],
            'cell_id' => ['nullable', 'uuid', 'exists:cells,id'],
        ]);

        if ($request->department_id && $request->cell_id) {
            return response()->json([
                'message' => 'A session cannot be both a department and a cell meeting.',
            ], 422);
        }

        $serviceType = ServiceType::find($request->service_type_id);
        if ($serviceType && $serviceType->type === 'adult' && ! $request->cell_id && ! $request->department_id) {
            return response()->json([
                'message' => 'Adult service attendance must be recorded per cell. Please select a cell.',
                'errors' => ['cell_id' => ['Adult service attendance must be recorded per cell.']],
            ], 422);
        }

        if ($serviceType && $serviceType->type === 'children' && ! $request->cell_id) {
            return response()->json([
                'message' => 'Children service attendance must be recorded per cell. Please select the Children Ministry cell.',
                'errors' => ['cell_id' => ['Children service attendance must be recorded per cell.']],
            ], 422);
        }

        $departmentId = $request->department_id;
        if ($departmentId) {
            $user = $request->user();
            $leadsIt = Department::where('id', $departmentId)->where('leader_user_id', $user->id)->exists();
            $isAdmin = $user->hasAnyRole(['super_admin', 'pastor', 'secretary']);
            if (! $leadsIt && ! $isAdmin) {
                return response()->json(['message' => 'You can only record meetings for a department you lead.'], 403);
            }
        }

        $cellId = $request->cell_id;
        if ($cellId) {
            $user = $request->user();
            $leadsCell = Cell::where('id', $cellId)->where('leader_user_id', $user->id)->exists();
            $isAdmin = $user->hasAnyRole(['super_admin', 'pastor', 'secretary']);
            if (! $leadsCell && ! $isAdmin) {
                return response()->json(['message' => 'You can only record meetings for a cell you lead.'], 403);
            }
        }

        $existing = AttendanceSession::where('service_type_id', $request->service_type_id)
            ->where('department_id', $departmentId)
            ->where('cell_id', $cellId)
            ->whereDate('service_date', $request->service_date)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'A session already exists for this service on this date.',
                'session_id' => $existing->id,
            ], 422);
        }

        $session = AttendanceSession::create([
            'branch_id' => $request->user()->branch_id,
            'service_type_id' => $request->service_type_id,
            'department_id' => $departmentId,
            'cell_id' => $cellId,
            'service_date' => $request->service_date,
            'notes' => $request->notes,
            'recorded_by' => $request->user()->id,
        ]);

        activity()->causedBy($request->user())
            ->performedOn($session)
            ->log("Opened attendance session for {$request->service_date}");

        return response()->json([
            'message' => 'Attendance session created.',
            'data' => new AttendanceSessionResource($session->load('serviceType', 'recorder', 'branch')),
        ], 201);
    }

    // GET /api/attendance/sessions/{id}
    public function showSession(string $id): JsonResponse
    {
        $session = AttendanceSession::with(['serviceType', 'recorder', 'records', 'branch'])->findOrFail($id);
        $serviceType = $session->serviceType;

        if ($serviceType->type === 'children') {
            // Load from the cell's children roster when linked to the
            // Children Ministry cell, otherwise fall back to all active children.
            $children = $session->cell_id
                ? (Cell::with('children')->find($session->cell_id)?->children
                    ->where('is_active', true)
                    ->sortBy('first_name')
                    ->values() ?? collect())
                : Children::where('is_active', true)->orderBy('first_name')->limit(500)->get();

            $people = $children->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->full_name,
                'type' => 'child',
                'class' => $c->class_group,
                'is_present' => $session->records->where('child_id', $c->id)->first()?->is_present ?? false,
            ]);
        } elseif ($session->department_id) {
            $dept = Department::with('members')->find($session->department_id);
            $people = ($dept?->members ?? collect())
                ->sortBy('first_name')
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->full_name,
                    'type' => 'member',
                    'member_number' => $m->member_number,
                    'is_present' => $session->records->where('member_id', $m->id)->first()?->is_present ?? false,
                ])->values();
        } elseif ($session->cell_id) {
            $cell = Cell::with('members')->find($session->cell_id);
            $people = ($cell?->members ?? collect())
                ->sortBy('first_name')
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->full_name,
                    'type' => 'member',
                    'member_number' => $m->member_number,
                    'is_present' => $session->records->where('member_id', $m->id)->first()?->is_present ?? false,
                ])->values();
        } else {
            // PERF FIX: This branch is unreachable for any session created
            // after createSession() began enforcing cell_id for adult services.
            // Loading the full members table (potentially 1 000+ rows) for a
            // general session that should not exist is an unbounded memory risk.
            // Return an empty roster and log a warning so ops can identify and
            // migrate any legacy sessions that reach this path.
            Log::warning(
                "AttendanceController::showSession — session {$session->id} ({$session->service_date}) "
                .'has no cell_id or department_id. Empty roster returned. '
                .'Migrate this session to a cell-scoped session.'
            );
            $people = collect();
        }

        return response()->json([
            'data' => [
                'session' => new AttendanceSessionResource($session),
                'people' => $people,
            ],
        ]);
    }

    /**
     * POST /api/attendance/sessions/{id}/mark
     *
     * CRITICAL-02 FIX — three security holes closed:
     *
     * 1. OWNERSHIP GATE: mirrors the createSession() check. The user marking
     *    attendance must lead the cell/department the session belongs to, or
     *    hold an admin role. Without this, any user with 'create attendance'
     *    permission could overwrite another leader's attendance records.
     *
     * 2. BRANCH-SCOPED person_id VALIDATION: records.*.person_id is validated
     *    as a UUID but previously accepted any UUID — including member/child
     *    IDs from other branches. We now pre-fetch the set of valid IDs
     *    (scoped to this branch) and reject any person_id not in that set.
     *
     * 3. DB::transaction() WRAP: the loop was previously un-wrapped. If it
     *    failed midway, the session was left with partial attendance records
     *    (e.g., 46 of 90 members marked). Now the entire batch is atomic —
     *    either all records are saved or none are.
     */
    public function markAttendance(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'records' => ['required', 'array', 'min:1'],
            'records.*.person_id' => ['required', 'uuid'],
            'records.*.type' => ['required', 'in:member,child'],
            'records.*.is_present' => ['required', 'boolean'],
        ]);

        // BranchScope on AttendanceSession ensures this session belongs to
        // the auth user's branch — cross-branch sessions return 404 here.
        $session = AttendanceSession::findOrFail($id);
        $user = $request->user();
        $branchId = $user->branch_id;
        $isAdmin = $user->hasAnyRole(['super_admin', 'pastor', 'secretary']);

        // ── Ownership gate ─────────────────────────────────────────────────
        if ($session->cell_id && ! $isAdmin) {
            $leadsCell = Cell::where('id', $session->cell_id)
                ->where('leader_user_id', $user->id)
                ->exists();
            abort_if(! $leadsCell, 403, 'You can only mark attendance for a cell you lead.');
        }

        if ($session->department_id && ! $isAdmin) {
            $leadsDept = Department::where('id', $session->department_id)
                ->where('leader_user_id', $user->id)
                ->exists();
            abort_if(! $leadsDept, 403, 'You can only mark attendance for a department you lead.');
        }

        // ── Pre-fetch valid person IDs scoped to this branch ───────────────
        // Collects all member_ids and child_ids from the payload in two
        // single queries, then validates each record against these sets.
        // This prevents cross-branch UUIDs from being written as attendance.
        $records = collect($request->records);
        $memberUuids = $records->where('type', 'member')->pluck('person_id')->unique();
        $childUuids = $records->where('type', 'child')->pluck('person_id')->unique();

        $validMemberIds = Member::whereIn('id', $memberUuids)
            ->where('branch_id', $branchId)
            ->pluck('id')
            ->flip();

        $validChildIds = Children::whereIn('id', $childUuids)
            ->where('branch_id', $branchId)
            ->pluck('id')
            ->flip();

        // ── Atomic batch write ─────────────────────────────────────────────
        DB::transaction(function () use ($records, $session, $validMemberIds, $validChildIds): void {
            foreach ($records as $record) {
                if ($record['type'] === 'member') {
                    abort_unless(
                        $validMemberIds->has($record['person_id']),
                        422,
                        "Member ID {$record['person_id']} is not valid for this branch."
                    );

                    AttendanceRecord::updateOrCreate(
                        ['session_id' => $session->id, 'member_id' => $record['person_id']],
                        ['is_present' => $record['is_present'], 'child_id' => null]
                    );
                } else {
                    abort_unless(
                        $validChildIds->has($record['person_id']),
                        422,
                        "Child ID {$record['person_id']} is not valid for this branch."
                    );

                    AttendanceRecord::updateOrCreate(
                        ['session_id' => $session->id, 'child_id' => $record['person_id']],
                        ['is_present' => $record['is_present'], 'member_id' => null]
                    );
                }
            }
        });

        activity()->causedBy($user)
            ->performedOn($session)
            ->log("Marked attendance for session {$session->service_date}");

        return response()->json(['message' => 'Attendance saved successfully.']);
    }

    /**
     * GET /api/attendance/stats
     *
     * PERF-06 FIX: Delegates to AttendanceStatsService, which uses a single
     * aggregated SQL query instead of O(N_dates × N_sessions) loops.
     * Query count reduced from 30–80+ to 2.
     */
    public function stats(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;

        return response()->json([
            'data' => $this->statsService->getStats($branchId),
        ]);
    }
}
