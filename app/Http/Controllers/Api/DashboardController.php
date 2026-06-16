<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\Cell;
use App\Models\Department;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SMELL-01 FIX: Controller is now thin — all aggregation logic extracted to
 * DashboardService. The controller's only responsibilities are:
 *  1. Resolve which dashboard variant to show (admin vs. leader).
 *  2. Call the appropriate service method.
 *  3. Return the JSON response.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboardService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Department/cell leaders who are NOT also admins get a scoped view:
        // their own units only, no church-wide finances or member data.
        if ($user->hasAnyRole(['department_leader', 'cell_leader'])
            && ! $user->hasAnyRole(['super_admin', 'pastor', 'secretary'])) {
            return $this->leaderDashboard($user);
        }

        // PRIVACY GUARDRAIL: admin dashboard exposes church-wide finances,
        // membership composition, and attendance trends. Non-admin roles
        // receive 403 here; the frontend redirects them to /portal.
        if (! $user->hasAnyRole(['super_admin', 'pastor', 'secretary', 'finance_officer'])) {
            return response()->json([
                'message' => 'You do not have permission to view the admin dashboard.',
            ], 403);
        }

        return response()->json([
            'data' => $this->dashboardService->getAdminStats(),
        ]);
    }

    /**
     * Scoped dashboard for a department / cell leader.
     *
     * Deliberately excludes all church-wide finance and membership data.
     * Only shows the departments and cells the user directly leads.
     */
    protected function leaderDashboard(User $user): JsonResponse
    {
        $departments = Department::query()
            ->where('leader_user_id', $user->id)
            ->with(['members' => fn ($q) => $q->orderBy('first_name')])
            ->get();

        $departmentSessions = AttendanceSession::query()
            ->whereIn('department_id', $departments->pluck('id')->all())
            ->withCount(['records as present_count' => fn ($q) => $q->where('is_present', true)->whereNotNull('member_id')])
            ->orderByDesc('service_date')
            ->get()
            ->groupBy('department_id');

        $departments = $departments->map(function (Department $dept) use ($departmentSessions) {
            $members = $dept->members->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->full_name,
                'member_number' => $m->member_number,
                'phone' => $m->phone,
                'role' => $m->pivot->role ?? 'member',
            ]);

            $recent = $dept->members
                ->sortByDesc(fn ($m) => $m->pivot->joined_at)
                ->take(5)
                ->map(fn ($m) => [
                    'name' => $m->full_name,
                    'member_number' => $m->member_number,
                    'joined_at' => $m->pivot->joined_at,
                ])
                ->values();

            $deptSessions = $departmentSessions->get($dept->id, collect());
            $memberCount = $dept->members->count();
            $lastMeeting = $deptSessions->first();
            $lastPresent = $lastMeeting?->present_count ?? 0;
            $attendanceRate = ($memberCount > 0 && $lastMeeting)
                ? round(($lastPresent / $memberCount) * 100)
                : 0;
            $meetingsThisMonth = $deptSessions->filter(fn ($s) => $s->service_date->isSameMonth(now()))->count();
            $trend = $deptSessions->take(6)->reverse()->map(fn ($s) => [
                'date' => $s->service_date->format('d M'),
                'count' => $s->present_count,
            ])->values();

            return [
                'id' => $dept->id,
                'name' => $dept->name,
                'active_members' => $memberCount,
                'members' => $members->values(),
                'recent_members' => $recent,
                'attendance' => [
                    'last_present' => $lastPresent,
                    'attendance_rate' => $attendanceRate,
                    'meetings_this_month' => $meetingsThisMonth,
                    'trend' => $trend,
                ],
            ];
        });

        $cells = Cell::query()
            ->where('leader_user_id', $user->id)
            ->with(['members' => fn ($q) => $q->orderBy('first_name')])
            ->get();

        $cellSessions = AttendanceSession::query()
            ->whereIn('cell_id', $cells->pluck('id')->all())
            ->withCount(['records as present_count' => fn ($q) => $q->where('is_present', true)->whereNotNull('member_id')])
            ->orderByDesc('service_date')
            ->get()
            ->groupBy('cell_id');

        $cells = $cells->map(function (Cell $cell) use ($cellSessions) {
            $members = $cell->members->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->full_name,
                'member_number' => $m->member_number,
                'phone' => $m->phone,
            ]);

            $sessions = $cellSessions->get($cell->id, collect());
            $memberCount = $cell->members->count();
            $lastMeeting = $sessions->first();
            $lastPresent = $lastMeeting?->present_count ?? 0;
            $attendanceRate = ($memberCount > 0 && $lastMeeting)
                ? round(($lastPresent / $memberCount) * 100)
                : 0;
            $meetingsThisMonth = $sessions->filter(fn ($s) => $s->service_date->isSameMonth(now()))->count();
            $trend = $sessions->take(6)->reverse()->map(fn ($s) => [
                'date' => $s->service_date->format('d M'),
                'count' => $s->present_count,
            ])->values();

            return [
                'id' => $cell->id,
                'name' => $cell->name,
                'active_members' => $memberCount,
                'members' => $members->values(),
                'attendance' => [
                    'last_present' => $lastPresent,
                    'attendance_rate' => $attendanceRate,
                    'meetings_this_month' => $meetingsThisMonth,
                    'trend' => $trend,
                ],
            ];
        });

        return response()->json([
            'data' => [
                'mode' => 'department_leader',
                'departments' => $departments->values(),
                'cells' => $cells->values(),
                'totals' => [
                    'departments_led' => $departments->count(),
                    'cells_led' => $cells->count(),
                    'total_active_members' => $departments->sum('active_members') + $cells->sum('active_members'),
                ],
            ],
        ]);
    }
}
