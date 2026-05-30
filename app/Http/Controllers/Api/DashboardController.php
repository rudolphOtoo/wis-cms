<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
use App\Models\Cell;
use App\Models\Department;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Department leaders (not also admins) get a scoped dashboard:
        // their own department(s) only, NO church finances or church-wide data.
        if ($user->hasAnyRole(['department_leader', 'cell_leader'])
            && ! $user->hasAnyRole(['super_admin', 'pastor', 'secretary'])) {
            return $this->leaderDashboard($user);
        }

        // PRIVACY GUARDRAIL: the admin payload includes church-wide
        // finances, membership composition, attendance trends, and
        // recent transactions. Only roles entrusted with church-wide
        // visibility may see it. Pure members and other non-admin
        // roles receive 403 here; the frontend redirects them to
        // /portal (their personal view).
        if (! $user->hasAnyRole(['super_admin', 'pastor', 'secretary', 'finance_officer'])) {
            return response()->json([
                'message' => 'You do not have permission to view the admin dashboard.',
            ], 403);
        }

        $branchId = $user->branch_id;
        $now = now();

        // === HERO STATS ===
        $totalMembers = Member::where('branch_id', $branchId)
            ->where('status', 'active')->count();

        $lastSession = AttendanceSession::where('branch_id', $branchId)
            ->whereHas('serviceType', fn ($q) => $q->where('type', '!=', 'children'))
            ->with('records')
            ->latest('service_date')
            ->first();
        $lastAttendance = $lastSession?->adult_count ?? 0;

        $monthIncome = Transaction::where('branch_id', $branchId)
            ->where('type', 'income')
            ->whereMonth('transaction_date', $now->month)
            ->whereYear('transaction_date', $now->year)
            ->sum('amount');

        $monthVisitors = Visitor::where('branch_id', $branchId)
            ->whereMonth('visit_date', $now->month)
            ->whereYear('visit_date', $now->year)
            ->count();

        // Previous-month comparison for growth indicator
        $prevMonth = $now->copy()->subMonth();
        $prevMonthIncome = Transaction::where('branch_id', $branchId)
            ->where('type', 'income')
            ->whereMonth('transaction_date', $prevMonth->month)
            ->whereYear('transaction_date', $prevMonth->year)
            ->sum('amount');

        $incomeGrowth = $prevMonthIncome > 0
            ? round((($monthIncome - $prevMonthIncome) / $prevMonthIncome) * 100, 1)
            : null;

        // === ATTENDANCE CHART — Last 8 adult sessions ===
        $attendanceChart = AttendanceSession::where('branch_id', $branchId)
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

        // === FINANCE CHART — Last 6 months income vs expense ===
        $financeChart = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $income = Transaction::where('branch_id', $branchId)
                ->where('type', 'income')
                ->whereMonth('transaction_date', $m->month)
                ->whereYear('transaction_date', $m->year)
                ->sum('amount');
            $expense = Transaction::where('branch_id', $branchId)
                ->where('type', 'expense')
                ->whereMonth('transaction_date', $m->month)
                ->whereYear('transaction_date', $m->year)
                ->sum('amount');
            $financeChart[] = [
                'month' => $m->format('M'),
                'income' => (float) $income,
                'expenses' => (float) $expense,
            ];
        }

        // === TOP CATEGORIES THIS MONTH ===
        $topCategories = Transaction::where('branch_id', $branchId)
            ->whereMonth('transaction_date', $now->month)
            ->whereYear('transaction_date', $now->year)
            ->where('type', 'income')
            ->selectRaw('category_id, SUM(amount) as total')
            ->groupBy('category_id')
            ->orderByDesc('total')
            ->take(5)
            ->with('category:id,name')
            ->get()
            ->map(fn ($t) => [
                'name' => $t->category?->name ?? 'Uncategorised',
                'total' => (float) $t->total,
            ]);

        // === GENDER SPLIT ===
        $male = Member::where('branch_id', $branchId)->where('gender', 'male')->where('status', 'active')->count();
        $female = Member::where('branch_id', $branchId)->where('gender', 'female')->where('status', 'active')->count();

        // === RECENT ACTIVITY ===
        $recentMembers = Member::where('branch_id', $branchId)
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($m) => [
                'name' => $m->full_name,
                'detail' => $m->member_number,
                'when' => $m->created_at->diffForHumans(),
            ]);

        $recentTransactions = Transaction::where('branch_id', $branchId)
            ->with(['category', 'member'])
            ->latest()
            ->take(5)
            ->get()
            ->map(fn ($t) => [
                'type' => $t->type,
                'amount' => (float) $t->amount,
                'detail' => $t->category?->name.($t->member ? ' · '.$t->member->full_name : ''),
                'when' => $t->created_at->diffForHumans(),
            ]);

        // === COUNTERS ===
        $counts = [
            'departments' => Department::where('branch_id', $branchId)->where('is_active', true)->count(),
            'pending_visitors' => Visitor::where('branch_id', $branchId)->where('follow_up_status', 'pending')->count(),
        ];

        return response()->json([
            'data' => [
                'hero' => [
                    'total_members' => $totalMembers,
                    'last_attendance' => $lastAttendance,
                    'month_income' => (float) $monthIncome,
                    'month_visitors' => $monthVisitors,
                    'income_growth' => $incomeGrowth,
                ],
                'gender_split' => [
                    'male' => $male,
                    'female' => $female,
                ],
                'counts' => $counts,
                'attendance_chart' => $attendanceChart,
                'finance_chart' => $financeChart,
                'top_categories' => $topCategories,
                'recent_members' => $recentMembers,
                'recent_transactions' => $recentTransactions,
            ],
        ]);
    }

    /**
     * Scoped dashboard for a department leader: only the department(s)
     * they lead, their members and recent additions. Deliberately
     * excludes all finance and church-wide data.
     */
    protected function leaderDashboard($user): JsonResponse
    {
        $departments = Department::where('branch_id', $user->branch_id)
            ->where('leader_user_id', $user->id)
            ->with(['members' => fn ($q) => $q->orderBy('first_name')])
            ->get()
            ->map(function ($dept) {
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

                // Department-meeting attendance stats (real, from sessions
                // scoped to this department).
                $deptSessions = AttendanceSession::where('branch_id', $dept->branch_id)
                    ->where('department_id', $dept->id)
                    ->withCount(['records as present_count' => fn ($q) => $q->where('is_present', true)->whereNotNull('member_id')])
                    ->orderByDesc('service_date')
                    ->get();

                $memberCount = $dept->members->count();
                $lastMeeting = $deptSessions->first();
                $lastPresent = $lastMeeting?->present_count ?? 0;
                $attendanceRate = ($memberCount > 0 && $lastMeeting)
                    ? round(($lastPresent / $memberCount) * 100)
                    : 0;
                $meetingsThisMonth = $deptSessions
                    ->filter(fn ($s) => $s->service_date->isSameMonth(now()))
                    ->count();
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

        // Cells the user leads (one-to-many members via cell_id). Mirrors
        // the department mapping; cell attendance is scoped by cell_id and
        // is empty until cell meetings are recorded.
        $cells = Cell::where('branch_id', $user->branch_id)
            ->where('leader_user_id', $user->id)
            ->with(['members' => fn ($q) => $q->orderBy('first_name')])
            ->get()
            ->map(function ($cell) {
                $members = $cell->members->map(fn ($m) => [
                    'id' => $m->id,
                    'name' => $m->full_name,
                    'member_number' => $m->member_number,
                    'phone' => $m->phone,
                ]);

                $sessions = AttendanceSession::where('branch_id', $cell->branch_id)
                    ->where('cell_id', $cell->id)
                    ->withCount(['records as present_count' => fn ($q) => $q->where('is_present', true)->whereNotNull('member_id')])
                    ->orderByDesc('service_date')
                    ->get();

                $memberCount = $cell->members->count();
                $lastMeeting = $sessions->first();
                $lastPresent = $lastMeeting?->present_count ?? 0;
                $attendanceRate = ($memberCount > 0 && $lastMeeting)
                    ? round(($lastPresent / $memberCount) * 100)
                    : 0;
                $meetingsThisMonth = $sessions
                    ->filter(fn ($s) => $s->service_date->isSameMonth(now()))
                    ->count();
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
