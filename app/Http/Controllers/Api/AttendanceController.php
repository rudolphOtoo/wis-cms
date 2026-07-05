<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\CreateAttendanceSessionRequest;
use App\Http\Requests\Attendance\MarkAttendanceRequest;
use App\Http\Resources\AttendanceSessionResource;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Cell;
use App\Models\Children;
use App\Models\Department;
use App\Models\ServiceType;
use App\Services\AttendanceStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceStatsService $statsService) {}

    /**
     * Resolve the attendance data scope based on the authenticated user's role.
     *
     * @return array{type: 'all'|'scoped', cell_ids: list<string>, department_ids: list<string>}
     */
    private function resolveScope(Request $request): array
    {
        $user = $request->user();

        // Admins see church-wide data
        if ($user->hasAnyRole(['super_admin', 'pastor', 'secretary', 'finance_officer'])) {
            return ['type' => 'all', 'cell_ids' => [], 'department_ids' => []];
        }

        $cellIds = $user->hasRole('cell_leader')
            ? Cell::where('leader_user_id', $user->id)->pluck('id')->toArray()
            : [];

        $deptIds = $user->hasRole('department_leader')
            ? Department::where('leader_user_id', $user->id)->pluck('id')->toArray()
            : [];

        return ['type' => 'scoped', 'cell_ids' => $cellIds, 'department_ids' => $deptIds];
    }

    // GET /api/attendance
    public function index(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);

        $query = AttendanceSession::query()
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
            ->orderByDesc('service_date');

        if ($scope['type'] === 'scoped') {
            $query->where(function ($q) use ($scope) {
                if (! empty($scope['cell_ids'])) {
                    $q->whereIn('cell_id', $scope['cell_ids']);
                }
                if (! empty($scope['department_ids'])) {
                    $q->orWhereIn('department_id', $scope['department_ids']);
                }
                if (empty($scope['cell_ids']) && empty($scope['department_ids'])) {
                    $q->whereRaw('0 = 1');
                }
            });
        }

        $sessions = $query->paginate($request->integer('per_page', 20));

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
    public function createSession(CreateAttendanceSessionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $departmentId = $validated['department_id'] ?? null;
        $cellId = $validated['cell_id'] ?? null;

        $existing = AttendanceSession::where('service_type_id', $validated['service_type_id'])
            ->where('department_id', $departmentId)
            ->where('cell_id', $cellId)
            ->whereDate('service_date', $validated['service_date'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'A session already exists for this service on this date.',
                'session_id' => $existing->id,
            ], 422);
        }

        $session = AttendanceSession::create([
            'branch_id' => $request->user()->branch_id,
            'service_type_id' => $validated['service_type_id'],
            'department_id' => $departmentId,
            'cell_id' => $cellId,
            'service_date' => $validated['service_date'],
            'notes' => $validated['notes'] ?? null,
            'recorded_by' => $request->user()->id,
        ]);

        activity()->causedBy($request->user())
            ->performedOn($session)
            ->log("Opened attendance session for {$validated['service_date']}");

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
     * 1. OWNERSHIP GATE: The FormRequest's authorize() ensures the user
     *    leads the cell/department the session belongs to, or holds an
     *    admin role. Without this, any user with 'create attendance'
     *    permission could overwrite another leader's attendance records.
     *
     * 2. BRANCH-SCOPED person_id VALIDATION: The FormRequest's withValidator
     *    pre-fetches the set of valid IDs (scoped to this branch) and rejects
     *    any person_id not in that set — preventing cross-branch UUIDs.
     *
     * 3. DB::transaction() WRAP: the loop was previously un-wrapped. If it
     *    failed midway, the session was left with partial attendance records
     *    (e.g., 46 of 90 members marked). Now the entire batch is atomic —
     *    either all records are saved or none are.
     */
    public function markAttendance(MarkAttendanceRequest $request, string $id): JsonResponse
    {
        $session = AttendanceSession::findOrFail($id);
        $this->authorize('markAttendance', $session);

        $records = collect($request->validated('records'));

        DB::transaction(function () use ($records, $session): void {
            foreach ($records as $record) {
                if ($record['type'] === 'member') {
                    AttendanceRecord::updateOrCreate(
                        ['session_id' => $session->id, 'member_id' => $record['person_id']],
                        ['is_present' => $record['is_present'], 'child_id' => null]
                    );
                } else {
                    AttendanceRecord::updateOrCreate(
                        ['session_id' => $session->id, 'child_id' => $record['person_id']],
                        ['is_present' => $record['is_present'], 'member_id' => null]
                    );
                }
            }
        });

        activity()->causedBy($request->user())
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
        $scope = $this->resolveScope($request);
        $branchId = $request->user()->branch_id;

        return response()->json([
            'data' => $this->statsService->getStats(
                $branchId,
                $scope['cell_ids'],
                $scope['department_ids'],
            ),
            'role_context' => [
                'type' => $scope['type'],
                'cells' => $scope['type'] === 'scoped'
                    ? Cell::whereIn('id', $scope['cell_ids'])->get(['id', 'name'])
                    : [],
            ],
        ]);
    }

    /**
     * GET /api/attendance/sundays
     *
     * Returns combined adult + children attendance per Sunday, using a
     * single performant query. Each row shows the Sunday date, adult count,
     * children count, and grand total — so the Children Ministry numbers
     * are seamlessly aggregated into the master church attendance.
     */
    public function sundays(Request $request): JsonResponse
    {
        $scope = $this->resolveScope($request);
        $branchId = $request->user()->branch_id;
        $perPage = $request->integer('per_page', 20);

        // Single query — no N+1. Uses FILTER clauses for adult/children split.
        $rows = DB::table('attendance_sessions as s')
            ->join('service_types as st', 's.service_type_id', '=', 'st.id')
            ->leftJoin('attendance_records as ar', function ($join) {
                $join->on('ar.session_id', '=', 's.id')
                    ->whereNull('ar.deleted_at')
                    ->where('ar.is_present', '=', true);
            })
            ->leftJoin('cells as c', 's.cell_id', '=', 'c.id')
            ->where('s.branch_id', $branchId)
            ->whereIn('st.slug', ['sunday_adult', 'sunday_children'])
            ->when($scope['type'] === 'scoped', fn ($q) => $q->where(function ($q) use ($scope) {
                if (! empty($scope['cell_ids'])) {
                    $q->whereIn('s.cell_id', $scope['cell_ids']);
                }
                if (! empty($scope['department_ids'])) {
                    $q->orWhereIn('s.department_id', $scope['department_ids']);
                }
                if (empty($scope['cell_ids']) && empty($scope['department_ids'])) {
                    $q->whereRaw('0 = 1');
                }
            }))
            ->select([
                's.service_date',
                DB::raw('COUNT(*) FILTER (WHERE ar.member_id IS NOT NULL) AS adult_count'),
                DB::raw('COUNT(*) FILTER (WHERE ar.child_id  IS NOT NULL) AS children_count'),
                DB::raw('COUNT(*) FILTER (WHERE ar.id IS NOT NULL) AS total_count'),
            ])
            ->groupBy('s.service_date')
            ->orderByDesc('s.service_date')
            ->paginate($perPage);

        return response()->json($rows);
    }
}
