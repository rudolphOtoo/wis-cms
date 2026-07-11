<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Computes per-member engagement metrics and welfare flags.
 *
 * Welfare flags are derived from attendance patterns over a configurable
 * window (branch-specific thresholds). The flag is denormalized on the
 * members table for fast queries but recomputed weekly by the
 * ComputeMemberWelfare artisan command.
 */
class MemberWelfareService
{
    /**
     * Build the member welfare dataset for a branch.
     *
     * @param  string  $branchId  Branch to scope data to.
     * @param  string|null  $cellId  Optional cell filter.
     * @param  string|null  $welfareFilter  Optional welfare flag filter.
     * @return array{
     *   period: array{from: string, to: string, window_weeks: int},
     *   members: list<array>,
     *   summary: array,
     * }
     */
    public function getWelfare(
        string $branchId,
        ?string $cellId = null,
        ?string $welfareFilter = null,
    ): array {
        $branch = Branch::findOrFail($branchId);
        $windowWeeks = $branch->engagement_window_weeks ?? 4;
        $windowStart = Carbon::now()->subWeeks($windowWeeks)->startOfWeek(Carbon::SUNDAY);
        $today = Carbon::today();

        // Total Sunday services in the window (denominator for attendance rate)
        $totalSundaysInWindow = DB::table('attendance_sessions as s')
            ->join('service_types as st', 's.service_type_id', '=', 'st.id')
            ->where('s.branch_id', $branchId)
            ->where('s.service_date', '>=', $windowStart)
            ->where('s.service_date', '<=', $today)
            ->where(function ($q) {
                $q->whereIn('st.slug', ['sunday_adult', 'sunday_children'])
                    ->orWhere(function ($q) {
                        $q->where('st.slug', 'cell_meeting')
                            ->whereRaw('EXTRACT(DOW FROM s.service_date) = 0');
                    });
            })
            ->selectRaw('COUNT(DISTINCT s.service_date) as count')
            ->value('count') ?? 1;

        // Active members query
        $membersQuery = Member::query()
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->with(['cell:id,name']);

        if ($cellId) {
            $membersQuery->where('cell_id', $cellId);
        }

        if ($welfareFilter && $welfareFilter !== 'all') {
            $membersQuery->where('welfare_flag', $welfareFilter);
        }

        $members = $membersQuery->get();

        // Compute per-member engagement
        $memberRows = $members->map(function (Member $member) use ($branchId, $windowStart, $totalSundaysInWindow) {
            // Attendance count in window
            $attendedServices = DB::table('attendance_records as ar')
                ->join('attendance_sessions as s', 'ar.session_id', '=', 's.id')
                ->join('service_types as st', 's.service_type_id', '=', 'st.id')
                ->where('ar.member_id', $member->id)
                ->where('ar.is_present', true)
                ->whereNull('ar.deleted_at')
                ->where('s.branch_id', $branchId)
                ->where('s.service_date', '>=', $windowStart)
                ->where(function ($q) {
                    $q->whereIn('st.slug', ['sunday_adult', 'sunday_children'])
                        ->orWhere(function ($q) {
                            $q->where('st.slug', 'cell_meeting')
                                ->whereRaw('EXTRACT(DOW FROM s.service_date) = 0');
                        });
                })
                ->count();

            // Giving in window
            $givingTotal = $member->transactions()
                ->where('type', 'income')
                ->where('transaction_date', '>=', $windowStart)
                ->sum('amount');

            $attendanceRate = $totalSundaysInWindow > 0
                ? round(($attendedServices / $totalSundaysInWindow) * 100, 1)
                : 0.0;

            return [
                'id' => $member->id,
                'name' => $member->full_name,
                'member_number' => $member->member_number,
                'cell_name' => $member->cell?->name ?? 'Unassigned',
                'cell_id' => $member->cell_id,
                'last_attendance_date' => $member->last_attendance_date?->format('Y-m-d'),
                'welfare_flag' => $member->welfare_flag ?? 'none',
                'attendance_rate' => $attendanceRate,
                'attended_services' => $attendedServices,
                'total_sundays_in_window' => $totalSundaysInWindow,
                'giving_total' => (float) $givingTotal,
            ];
        })->sortByDesc('attendance_rate')->values()->all();

        // Summary
        $flagCounts = collect($memberRows)->groupBy('welfare_flag')
            ->map(fn ($g) => count($g))
            ->toArray();

        $totalMembers = count($memberRows);
        $avgAttendance = $totalMembers > 0
            ? round(collect($memberRows)->avg('attendance_rate'), 1)
            : 0.0;

        // Per-cell summary
        $byCell = collect($memberRows)
            ->groupBy('cell_name')
            ->map(function ($cellMembers, $cellName) {
                return [
                    'name' => $cellName,
                    'member_count' => count($cellMembers),
                    'avg_attendance_rate' => round(collect($cellMembers)->avg('attendance_rate'), 1),
                    'engaged' => collect($cellMembers)->where('welfare_flag', 'engaged')->count(),
                    'moderate' => collect($cellMembers)->where('welfare_flag', 'moderate')->count(),
                    'at_risk' => collect($cellMembers)->where('welfare_flag', 'at_risk')->count(),
                    'inactive_risk' => collect($cellMembers)->where('welfare_flag', 'inactive_risk')->count(),
                ];
            })
            ->values()
            ->all();

        return [
            'period' => [
                'from' => $windowStart->toDateString(),
                'to' => $today->toDateString(),
                'window_weeks' => $windowWeeks,
            ],
            'members' => $memberRows,
            'summary' => [
                'total_members' => $totalMembers,
                'avg_attendance_rate' => $avgAttendance,
                'total_sundays_in_window' => $totalSundaysInWindow,
                'flag_counts' => $flagCounts,
                'by_cell' => $byCell,
            ],
        ];
    }
}
