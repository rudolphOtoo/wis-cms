<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Half-open date ranges ([start, end)) for index-friendly filtering.
 *
 * Laravel's whereDate()/whereMonth()/whereYear() wrap the column in a
 * cast (e.g. `col::date = ?`, `EXTRACT(MONTH FROM col) = ?`), which
 * makes the predicate non-sargable and defeats every index on the
 * column. Comparing the raw column against an explicit half-open range
 * keeps the index usable while producing identical results for both
 * `date` and `timestamp` columns.
 */
final class DateRange
{
    private function __construct() {}

    /**
     * The calendar day containing $date: [00:00, next midnight).
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public static function day(CarbonInterface|string $date): array
    {
        $start = CarbonImmutable::parse($date)->startOfDay();

        return [$start, $start->addDay()];
    }

    /**
     * The calendar month containing $date: [1st 00:00, 1st of next month).
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public static function month(CarbonInterface|string $date): array
    {
        $start = CarbonImmutable::parse($date)->startOfMonth();

        return [$start, $start->addMonthNoOverflow()];
    }

    /**
     * The calendar year $year: [Jan 1, Jan 1 of next year).
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    public static function year(int $year): array
    {
        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();

        return [$start, $start->addYear()];
    }
}
