<?php

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
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    // GET /api/attendance
    public function index(Request $request): JsonResponse
    {
        $sessions = AttendanceSession::query()
            ->where('branch_id', $request->user()->branch_id)
            ->with(['serviceType', 'recorder'])
            ->orderByDesc('service_date')
            ->paginate($request->get('per_page', 20));

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

        // A session is one of: church service, department meeting, OR cell meeting.
        // Cannot be both a dept and a cell meeting.
        if ($request->department_id && $request->cell_id) {
            return response()->json([
                'message' => 'A session cannot be both a department and a cell meeting.',
            ], 422);
        }

        // If a department is given, the user must actually lead it
        // (unless they're an admin-type role). Prevents recording meetings
        // for departments you don't lead.
        $departmentId = $request->department_id;
        if ($departmentId) {
            $user = $request->user();
            $leadsIt = Department::where('id', $departmentId)
                ->where('branch_id', $user->branch_id)
                ->where('leader_user_id', $user->id)
                ->exists();
            $isAdmin = $user->hasAnyRole(['super_admin', 'pastor', 'secretary']);
            if (! $leadsIt && ! $isAdmin) {
                return response()->json([
                    'message' => 'You can only record meetings for a department you lead.',
                ], 403);
            }
        }

        // Same scoping for cells: a cell_leader can only record meetings
        // for cells they actually lead.
        $cellId = $request->cell_id;
        if ($cellId) {
            $user = $request->user();
            $leadsCell = Cell::where('id', $cellId)
                ->where('branch_id', $user->branch_id)
                ->where('leader_user_id', $user->id)
                ->exists();
            $isAdmin = $user->hasAnyRole(['super_admin', 'pastor', 'secretary']);
            if (! $leadsCell && ! $isAdmin) {
                return response()->json([
                    'message' => 'You can only record meetings for a cell you lead.',
                ], 403);
            }
        }

        // Prevent duplicate session (scoped by the right axis: service / dept / cell)
        $existing = AttendanceSession::where('branch_id', $request->user()->branch_id)
            ->where('service_type_id', $request->service_type_id)
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
            'data' => new AttendanceSessionResource($session->load('serviceType', 'recorder')),
        ], 201);
    }

    // GET /api/attendance/sessions/{id}
    public function showSession(Request $request, string $id): JsonResponse
    {
        $session = AttendanceSession::where('branch_id', $request->user()->branch_id)
            ->with(['serviceType', 'recorder', 'records'])
            ->findOrFail($id);

        // Get all members/children with their attendance status
        $serviceType = $session->serviceType;

        if ($serviceType->type === 'children') {
            $people = Children::where('branch_id', $request->user()->branch_id)
                ->where('is_active', true)
                ->orderBy('first_name')
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->full_name,
                    'type' => 'child',
                    'class' => $c->class_group,
                    'is_present' => $session->records->where('child_id', $c->id)->first()?->is_present ?? false,
                ]);
        } elseif ($session->department_id) {
            // Department meeting: only this department's members
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
            // Cell meeting: only this cell's members (one-to-many via cell_id)
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
            $people = Member::where('branch_id', $request->user()->branch_id)
                ->where('status', 'active')
                ->orderBy('first_name')
                ->get()
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->full_name,
                    'type' => 'member',
                    'member_number' => $m->member_number,
                    'is_present' => $session->records->where('member_id', $m->id)->first()?->is_present ?? false,
                ]);
        }

        return response()->json([
            'data' => [
                'session' => new AttendanceSessionResource($session),
                'people' => $people,
            ],
        ]);
    }

    // POST /api/attendance/sessions/{id}/mark
    public function markAttendance(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'records' => ['required', 'array'],
            'records.*.person_id' => ['required', 'uuid'],
            'records.*.type' => ['required', 'in:member,child'],
            'records.*.is_present' => ['required', 'boolean'],
        ]);

        $session = AttendanceSession::where('branch_id', $request->user()->branch_id)
            ->findOrFail($id);

        foreach ($request->records as $record) {
            $data = [
                'session_id' => $session->id,
                'is_present' => $record['is_present'],
            ];

            if ($record['type'] === 'member') {
                $data['member_id'] = $record['person_id'];
                $data['child_id'] = null;
                AttendanceRecord::updateOrCreate(
                    ['session_id' => $session->id, 'member_id' => $record['person_id']],
                    $data
                );
            } else {
                $data['child_id'] = $record['person_id'];
                $data['member_id'] = null;
                AttendanceRecord::updateOrCreate(
                    ['session_id' => $session->id, 'child_id' => $record['person_id']],
                    $data
                );
            }
        }

        activity()->causedBy($request->user())
            ->performedOn($session)
            ->log("Marked attendance for session {$session->service_date}");

        return response()->json(['message' => 'Attendance saved successfully.']);
    }

    // GET /api/attendance/stats
    public function stats(Request $request): JsonResponse
    {
        $branchId = $request->user()->branch_id;

        $lastSession = AttendanceSession::where('branch_id', $branchId)
            ->whereHas('serviceType', fn ($q) => $q->where('type', '!=', 'children'))
            ->with('records')
            ->latest('service_date')
            ->first();

        $lastSunday = $lastSession?->adult_count ?? 0;
        $totalSessions = AttendanceSession::where('branch_id', $branchId)->count();

        // Average adult attendance last 4 sessions
        $recentSessions = AttendanceSession::where('branch_id', $branchId)
            ->whereHas('serviceType', fn ($q) => $q->where('type', 'adult'))
            ->with('records')
            ->latest('service_date')
            ->take(4)
            ->get();

        $avgAttendance = $recentSessions->count() > 0
            ? round($recentSessions->avg(fn ($s) => $s->adult_count))
            : 0;

        // Last 8 sessions for chart
        $chartData = AttendanceSession::where('branch_id', $branchId)
            ->whereHas('serviceType', fn ($q) => $q->where('type', 'adult'))
            ->with(['serviceType', 'records'])
            ->latest('service_date')
            ->take(8)
            ->get()
            ->reverse()
            ->map(fn ($s) => [
                'date' => $s->service_date->format('d M'),
                'count' => $s->adult_count,
            ])
            ->values();

        // Week-over-week: most recent adult session vs the one before it
        $lastTwo = AttendanceSession::where('branch_id', $branchId)
            ->whereHas('serviceType', fn ($q) => $q->where('type', 'adult'))
            ->with('records')
            ->latest('service_date')
            ->take(2)
            ->get();
        $weekOverWeek = null;
        if ($lastTwo->count() === 2) {
            $current = $lastTwo[0]->adult_count;
            $previous = $lastTwo[1]->adult_count;
            if ($previous > 0) {
                $weekOverWeek = round((($current - $previous) / $previous) * 100, 1);
            }
        }

        // Monthly trend: total attendance grouped by month, last 6 months
        $monthlyTrend = AttendanceSession::where('branch_id', $branchId)
            ->where('service_date', '>=', now()->subMonths(6)->startOfMonth())
            ->with('records')
            ->get()
            ->groupBy(fn ($s) => $s->service_date->format('Y-m'))
            ->map(fn ($group, $key) => [
                'month' => Carbon::createFromFormat('Y-m', $key)->format('M'),
                'total' => $group->sum(fn ($s) => $s->total_count),
            ])
            ->values();

        // Insights: real computed facts for leadership
        $allAdult = AttendanceSession::where('branch_id', $branchId)
            ->whereHas('serviceType', fn ($q) => $q->where('type', 'adult'))
            ->with(['serviceType', 'records'])
            ->get();
        $topService = $allAdult
            ->groupBy(fn ($s) => $s->serviceType?->name ?? 'Service')
            ->map(fn ($group) => $group->avg(fn ($s) => $s->adult_count))
            ->sortDesc()
            ->keys()
            ->first();
        $avgAdults = $allAdult->count() > 0 ? round($allAdult->avg(fn ($s) => $s->adult_count)) : 0;
        $avgChildren = $allAdult->count() > 0 ? round($allAdult->avg(fn ($s) => $s->children_count)) : 0;
        $trendDirection = 'flat';
        if ($monthlyTrend->count() >= 2) {
            $last = $monthlyTrend->last()['total'];
            $prev = $monthlyTrend[$monthlyTrend->count() - 2]['total'];
            $trendDirection = $last > $prev ? 'up' : ($last < $prev ? 'down' : 'flat');
        }

        return response()->json([
            'data' => [
                'last_sunday' => $lastSunday,
                'average' => $avgAttendance,
                'total_sessions' => $totalSessions,
                'chart' => $chartData,
                'monthly_trend' => $monthlyTrend,
                'week_over_week_pct' => $weekOverWeek,
                'insights' => [
                    'top_service' => $topService ?? 'N/A',
                    'avg_adults' => $avgAdults,
                    'avg_children' => $avgChildren,
                    'trend_direction' => $trendDirection,
                ],
            ],
        ]);
    }
}
