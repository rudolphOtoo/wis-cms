<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSession;
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
        $branchId = $request->user()->branch_id;
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
}
