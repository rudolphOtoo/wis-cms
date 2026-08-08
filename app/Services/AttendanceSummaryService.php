<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Cell;
use App\Models\Member;
use App\Support\AttendanceCounts;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates cell-level attendance into church-wide totals per Sunday.
 *
 * Enriched for the Leaders' Meeting executive report: each cell row
 * includes member count, attendance rate, welfare distribution, and
 * recent pastoral note counts. The summary includes overall attendance
 * rate and a welfare snapshot.
 */
class AttendanceSummaryService
{
    /**
     * Build the attendance summary dataset.
     *
     * @param  string  $branchId  Branch to scope data to.
     * @param  string|null  $fromDate  Start date (inclusive).
     * @param  string|null  $toDate  End date (inclusive).
     * @param  string|null  $cellId  Optional cell filter.
     * @return array{
     *   period: array{from: string, to: string},
     *   sundays: list<array{
     *     service_date: string,
     *     date_label: string,
     *     adult_count: int,
     *     children_count: int,
     *     total_count: int,
     *     by_cell: list<array{name: string, count: int, adult_count: int, children_count: int, member_count: int, attendance_rate: float|null, welfare_distribution: array, recent_pastoral_notes_count: int}>,
     *   }>,
     *   cell_summary: list<array>,
     *   summary: array{
     *     total_sundays: int,
     *     total_attendance: int,
     *     avg_per_sunday: float,
     *     highest_sunday: string|null,
     *     highest_count: int,
     *     lowest_sunday: string|null,
     *     lowest_count: int,
     *     avg_adults: float,
     *     avg_children: float,
     *     total_active_members: int,
     *     overall_attendance_rate: float,
     *     welfare_summary: array,
     *     cells_at_risk: list<string>,
     *     trend: array{direction: string, recent_avg: float, prior_avg: float, delta: float},
     *   },
     * }
     */
    public function getSummary(
        string $branchId,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $cellId = null,
    ): array {
        $to = $toDate ? Carbon::parse($toDate) : Carbon::today();
        $from = $fromDate ? Carbon::parse($fromDate) : $to->copy()->subWeeks(12)->startOfWeek(Carbon::SUNDAY);

        // ── Pass A: attendance aggregates per cell per Sunday ──────
        $totalActiveMembers = Member::where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->count();

        $query = DB::table('attendance_sessions as s')
            ->leftJoinLateral(AttendanceCounts::subquery('s'), 'c')
            ->join('service_types as st', 's.service_type_id', '=', 'st.id')
            ->leftJoin('cells as cell', 's.cell_id', '=', 'cell.id')
            ->where('s.branch_id', $branchId)
            ->where('s.service_date', '>=', $from)
            ->where('s.service_date', '<=', $to)
            ->where(function ($q) {
                $q->whereIn('st.slug', ['sunday_adult', 'sunday_children'])
                    ->orWhere(function ($q) {
                        $q->where('st.slug', 'cell_meeting')
                            ->whereRaw('EXTRACT(DOW FROM s.service_date) = 0');
                    });
            })
            ->select([
                's.service_date',
                's.cell_id',
                DB::raw("COALESCE(cell.name, 'Unassigned') AS cell_name"),
                'c.adult_count',
                'c.children_count',
            ])
            ->orderByDesc('s.service_date')
            ->orderBy('cell.name');

        if ($cellId) {
            $query->where('s.cell_id', $cellId);
        }

        $rows = $query->get();

        // ── Pass B: per-cell member counts + welfare distribution ──
        $cellMembers = Member::where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->select(
                'cell_id',
                DB::raw('COUNT(*) AS member_count'),
                DB::raw("SUM(CASE WHEN welfare_flag = 'engaged' THEN 1 ELSE 0 END) AS engaged"),
                DB::raw("SUM(CASE WHEN welfare_flag = 'moderate' THEN 1 ELSE 0 END) AS moderate"),
                DB::raw("SUM(CASE WHEN welfare_flag = 'at_risk' THEN 1 ELSE 0 END) AS at_risk"),
                DB::raw("SUM(CASE WHEN welfare_flag = 'inactive_risk' THEN 1 ELSE 0 END) AS inactive_risk"),
            )
            ->groupBy('cell_id')
            ->get()
            ->keyBy('cell_id');

        // ── Pass C: recent pastoral notes per cell (last 4 weeks) ─
        $fourWeeksAgo = Carbon::now()->subWeeks(4)->toDateString();
        $pastoralByCell = DB::table('pastoral_notes as pn')
            ->join('members as m', 'pn.member_id', '=', 'm.id')
            ->where('pn.branch_id', $branchId)
            ->whereNull('pn.deleted_at')
            ->where('pn.created_at', '>=', $fourWeeksAgo)
            ->select('m.cell_id', DB::raw('COUNT(*) AS notes_count'))
            ->groupBy('m.cell_id')
            ->get()
            ->keyBy('cell_id');

        // ── Pass D: global welfare summary ─────────────────────────
        $welfareSummary = Member::where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->select(
                DB::raw("SUM(CASE WHEN welfare_flag = 'engaged' THEN 1 ELSE 0 END) AS engaged"),
                DB::raw("SUM(CASE WHEN welfare_flag = 'moderate' THEN 1 ELSE 0 END) AS moderate"),
                DB::raw("SUM(CASE WHEN welfare_flag = 'at_risk' THEN 1 ELSE 0 END) AS at_risk"),
                DB::raw("SUM(CASE WHEN welfare_flag = 'inactive_risk' THEN 1 ELSE 0 END) AS inactive_risk"),
            )
            ->first();

        // ── Build per-Sunday rows ──────────────────────────────────
        /** @var Collection<string, Collection> $byDate */
        $byDate = $rows->groupBy('service_date');

        $sundays = [];
        foreach ($byDate as $date => $cells) {
            $adultCount = (int) $cells->sum('adult_count');
            $childrenCount = (int) $cells->sum('children_count');
            $totalCount = $adultCount + $childrenCount;

            $byCell = $cells->map(function ($r) use ($cellMembers, $pastoralByCell) {
                $cm = $cellMembers->get($r->cell_id);
                $memberCount = $cm ? (int) $cm->member_count : 0;
                $attCount = (int) $r->adult_count + (int) $r->children_count;

                return [
                    'cell_id' => $r->cell_id,
                    'name' => $r->cell_name,
                    'count' => $attCount,
                    'adult_count' => (int) $r->adult_count,
                    'children_count' => (int) $r->children_count,
                    'member_count' => $memberCount,
                    'attendance_rate' => $memberCount > 0
                        ? round(($attCount / $memberCount) * 100, 1)
                        : null,
                    'welfare_distribution' => $cm ? [
                        'engaged' => (int) $cm->engaged,
                        'moderate' => (int) $cm->moderate,
                        'at_risk' => (int) $cm->at_risk,
                        'inactive_risk' => (int) $cm->inactive_risk,
                    ] : ['engaged' => 0, 'moderate' => 0, 'at_risk' => 0, 'inactive_risk' => 0],
                    'recent_pastoral_notes_count' => $pastoralByCell->has($r->cell_id)
                        ? (int) $pastoralByCell->get($r->cell_id)->notes_count
                        : 0,
                ];
            })->values()->all();

            $sundays[] = [
                'service_date' => $date,
                'date_label' => Carbon::parse($date)->format('M j, Y'),
                'adult_count' => $adultCount,
                'children_count' => $childrenCount,
                'total_count' => $totalCount,
                'by_cell' => $byCell,
            ];
        }

        // ── Build cell summary (one row per cell, aggregated) ──────
        // Members without a cell (cell_id = NULL) group under an EMPTY
        // string key here — PHP arrays can't use null as a key, so
        // keyBy('cell_id') coerces it to ''. Never pass that empty string
        // to Cell::find(): Postgres rejects '' as a uuid and the report
        // would 500. Load all cell names in one query instead.
        $knownCellIds = $cellMembers->keys()->filter()->values()->all();
        $cellsById = Cell::whereIn('id', $knownCellIds)->pluck('name', 'id');

        $cellSummary = [];
        foreach ($cellMembers as $cellId => $cm) {
            // Average attendance across Sundays this cell appeared
            $cellSundays = [];
            foreach ($sundays as $sunday) {
                foreach ($sunday['by_cell'] as $bc) {
                    if (($bc['cell_id'] ?? null) === $cellId) {
                        $cellSundays[] = $bc['count'];
                    }
                }
            }
            $avgAtt = count($cellSundays) > 0
                ? round(array_sum($cellSundays) / count($cellSundays), 1)
                : 0.0;
            $memberCount = (int) $cm->member_count;

            $cellSummary[] = [
                'cell_id' => $cellId,
                'name' => $cellsById[$cellId] ?? 'Unassigned',
                'member_count' => $memberCount,
                'avg_attendance' => $avgAtt,
                'attendance_rate' => $memberCount > 0
                    ? round(($avgAtt / $memberCount) * 100, 1)
                    : null,
                'welfare_distribution' => [
                    'engaged' => (int) $cm->engaged,
                    'moderate' => (int) $cm->moderate,
                    'at_risk' => (int) $cm->at_risk,
                    'inactive_risk' => (int) $cm->inactive_risk,
                ],
                'recent_pastoral_notes_count' => $pastoralByCell->has($cellId)
                    ? (int) $pastoralByCell->get($cellId)->notes_count
                    : 0,
            ];
        }

        // Sort cell summary by member count descending
        usort($cellSummary, fn ($a, $b) => $b['member_count'] <=> $a['member_count']);

        // ── Summary statistics ──────────────────────────────────────
        $totalSundays = count($sundays);
        $totalAttendance = array_sum(array_column($sundays, 'total_count'));
        $avgPerSunday = $totalSundays > 0 ? round($totalAttendance / $totalSundays, 1) : 0.0;
        $avgAdults = $totalSundays > 0 ? round(array_sum(array_column($sundays, 'adult_count')) / $totalSundays, 1) : 0.0;
        $avgChildren = $totalSundays > 0 ? round(array_sum(array_column($sundays, 'children_count')) / $totalSundays, 1) : 0.0;

        // Overall attendance rate: total attendance across all Sundays / (active members * Sundays)
        $overallRate = ($totalActiveMembers > 0 && $totalSundays > 0)
            ? round(($totalAttendance / ($totalActiveMembers * $totalSundays)) * 100, 1)
            : 0.0;

        // Highest and lowest Sundays
        $highestIdx = $totalSundays > 0 ? array_search(max(array_column($sundays, 'total_count')), array_column($sundays, 'total_count')) : false;
        $lowestIdx = $totalSundays > 0 ? array_search(min(array_column($sundays, 'total_count')), array_column($sundays, 'total_count')) : false;

        // Trend: compare last 4 Sundays avg vs prior 4 Sundays avg
        $recent4 = array_slice($sundays, 0, min(4, $totalSundays));
        $prior4 = array_slice($sundays, min(4, $totalSundays), 4);

        $recentAvg = count($recent4) > 0
            ? round(array_sum(array_column($recent4, 'total_count')) / count($recent4), 1)
            : 0.0;
        $priorAvg = count($prior4) > 0
            ? round(array_sum(array_column($prior4, 'total_count')) / count($prior4), 1)
            : 0.0;

        $delta = round($recentAvg - $priorAvg, 1);
        $trendDirection = match (true) {
            count($prior4) === 0 => 'unknown',
            $delta >= 3 => 'up',
            $delta <= -3 => 'down',
            default => 'flat',
        };

        // Cells at risk: those with attendance rate < 50% (of their members)
        $cellsAtRisk = array_values(array_map(
            fn ($c) => $c['name'],
            array_filter($cellSummary, fn ($c) => $c['attendance_rate'] !== null && $c['attendance_rate'] < 50),
        ));

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'sundays' => $sundays,
            'cell_summary' => $cellSummary,
            'summary' => [
                'total_sundays' => $totalSundays,
                'total_attendance' => $totalAttendance,
                'avg_per_sunday' => $avgPerSunday,
                'highest_sunday' => $highestIdx !== false ? $sundays[$highestIdx]['date_label'] : null,
                'highest_count' => $highestIdx !== false ? $sundays[$highestIdx]['total_count'] : 0,
                'lowest_sunday' => $lowestIdx !== false ? $sundays[$lowestIdx]['date_label'] : null,
                'lowest_count' => $lowestIdx !== false ? $sundays[$lowestIdx]['total_count'] : 0,
                'avg_adults' => $avgAdults,
                'avg_children' => $avgChildren,
                'total_active_members' => $totalActiveMembers,
                'overall_attendance_rate' => $overallRate,
                'welfare_summary' => [
                    'engaged' => (int) ($welfareSummary->engaged ?? 0),
                    'moderate' => (int) ($welfareSummary->moderate ?? 0),
                    'at_risk' => (int) ($welfareSummary->at_risk ?? 0),
                    'inactive_risk' => (int) ($welfareSummary->inactive_risk ?? 0),
                ],
                'cells_at_risk' => $cellsAtRisk,
                'trend' => [
                    'direction' => $trendDirection,
                    'recent_avg' => $recentAvg,
                    'prior_avg' => $priorAvg,
                    'delta' => $delta,
                ],
            ],
        ];
    }
}
