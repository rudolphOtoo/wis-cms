<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregation reads over mv_biometric_attendance_daily.
 *
 * The materialized view is pre-grouped by branch / service_date / cell /
 * department, so every method here is a single index-scoped scan. Callers
 * use these rows INSTEAD of re-counting attendance_records for dates that
 * have biometric data.
 *
 * Church-wide totals are derived by summing the per-cell rows — the
 * "aggregate across all individual cells" rule. Refresh cadence is owned by
 * the biometric:refresh-view command.
 */
class BiometricAttendanceService
{
    public const VIEW = 'mv_biometric_attendance_daily';

    /**
     * Service dates (Y-m-d) that have at least one biometric record for the
     * branch. Used to make biometric data authoritative for a date so manual
     * cell sessions of the same date are not double-counted.
     *
     * @return list<string>
     */
    public function coveredDates(
        string $branchId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        array $cellIds = [],
        array $departmentIds = [],
    ): array {
        return $this->scopeFilter($this->baseQuery($branchId, $from, $to), $cellIds, $departmentIds)
            ->distinct()
            ->pluck('service_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();
    }

    /**
     * Church-wide biometric totals per service date (summed across cells).
     *
     * @param  list<string>  $cellIds  When non-empty, scope to these cells.
     * @param  list<string>  $departmentIds  When non-empty, scope to these departments.
     * @return Collection<int, object{service_date: string, adult_count: int, children_count: int, total_count: int}>
     */
    public function dailyTotals(
        string $branchId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        array $cellIds = [],
        array $departmentIds = [],
    ): Collection {
        $query = $this->scopeFilter($this->baseQuery($branchId, $from, $to), $cellIds, $departmentIds);

        return $query
            ->select(
                self::VIEW.'.service_date',
                DB::raw('SUM('.self::VIEW.'.adult_count) AS adult_count'),
                DB::raw('SUM('.self::VIEW.'.children_count) AS children_count'),
                DB::raw('SUM('.self::VIEW.'.total_count) AS total_count'),
            )
            ->groupBy(self::VIEW.'.service_date')
            ->orderByDesc(self::VIEW.'.service_date')
            ->get()
            ->map(fn ($row) => (object) [
                'service_date' => Carbon::parse($row->service_date)->toDateString(),
                'adult_count' => (int) $row->adult_count,
                'children_count' => (int) $row->children_count,
                'total_count' => (int) $row->total_count,
            ]);
    }

    /**
     * Per-cell biometric rows (one per date/cell) shaped for the reporting
     * services to merge alongside their manual aggregates.
     *
     * @return Collection<int, object{service_date: string, cell_id: string|null, department_id: string|null, cell_name: string, adult_count: int, children_count: int}>
     */
    public function aggregateRows(
        string $branchId,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        array $cellIds = [],
        array $departmentIds = [],
    ): Collection {
        return $this->scopeFilter($this->baseQuery($branchId, $from, $to), $cellIds, $departmentIds)
            ->leftJoin('cells as cell', self::VIEW.'.cell_id', '=', 'cell.id')
            ->select(
                self::VIEW.'.service_date',
                self::VIEW.'.cell_id',
                self::VIEW.'.department_id',
                DB::raw("COALESCE(cell.name, 'Unassigned') AS cell_name"),
                self::VIEW.'.adult_count',
                self::VIEW.'.children_count',
            )
            ->orderByDesc(self::VIEW.'.service_date')
            ->get()
            ->map(fn ($row) => (object) [
                'service_date' => Carbon::parse($row->service_date)->toDateString(),
                'cell_id' => $row->cell_id,
                'department_id' => $row->department_id,
                'cell_name' => $row->cell_name,
                'adult_count' => (int) $row->adult_count,
                'children_count' => (int) $row->children_count,
            ]);
    }

    /**
     * Per-cell totals for one service date.
     *
     * @return Collection<int, object{cell_id: string|null, cell_name: string, adult_count: int, children_count: int, total_count: int}>
     */
    public function cellTotalsForDate(string $branchId, string $date): Collection
    {
        return DB::table(self::VIEW)
            ->leftJoin('cells as cell', self::VIEW.'.cell_id', '=', 'cell.id')
            ->where(self::VIEW.'.branch_id', $branchId)
            ->where(self::VIEW.'.service_date', $date)
            ->select(
                self::VIEW.'.cell_id',
                DB::raw("COALESCE(cell.name, 'Unassigned') AS cell_name"),
                self::VIEW.'.adult_count',
                self::VIEW.'.children_count',
                self::VIEW.'.total_count',
            )
            ->orderByDesc(self::VIEW.'.total_count')
            ->get()
            ->map(fn ($row) => (object) [
                'cell_id' => $row->cell_id,
                'cell_name' => $row->cell_name,
                'adult_count' => (int) $row->adult_count,
                'children_count' => (int) $row->children_count,
                'total_count' => (int) $row->total_count,
            ]);
    }

    public function hasData(string $branchId): bool
    {
        return DB::table(self::VIEW)->where(self::VIEW.'.branch_id', $branchId)->exists();
    }

    /**
     * Restrict a view query to a leader's visible cells / departments.
     *
     * Empty lists mean "no restriction"; a member is either in the cell or
     * in the department, so both filters are OR-ed together — mirroring the
     * manual session scoping in the controllers and report services.
     *
     * @param  list<string>  $cellIds
     * @param  list<string>  $departmentIds
     */
    protected function scopeFilter(Builder $query, array $cellIds, array $departmentIds): Builder
    {
        if ($cellIds === [] && $departmentIds === []) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($cellIds, $departmentIds) {
            if ($cellIds !== []) {
                $q->whereIn(self::VIEW.'.cell_id', $cellIds);
            }
            if ($departmentIds !== []) {
                $q->orWhereIn(self::VIEW.'.department_id', $departmentIds);
            }
        });
    }

    protected function baseQuery(string $branchId, ?CarbonInterface $from, ?CarbonInterface $to): Builder
    {
        // Columns are qualified with the view name: callers join `cells`,
        // which also carries branch_id, and an unqualified reference would
        // be ambiguous.
        return DB::table(self::VIEW)
            ->where(self::VIEW.'.branch_id', $branchId)
            ->when($from, fn ($q) => $q->where(self::VIEW.'.service_date', '>=', $from))
            ->when($to, fn ($q) => $q->where(self::VIEW.'.service_date', '<=', $to));
    }
}
