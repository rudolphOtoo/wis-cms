<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes welfare_flag and last_attendance_date for all active members
 * across all branches. Scheduled weekly on Sunday evening after services.
 *
 * Welfare flag logic:
 *   - engaged:      attended >= 75% of services in window
 *   - moderate:     attended >= at_risk_threshold_pct (default 50%)
 *   - at_risk:      attended > 0% but < threshold
 *   - inactive_risk: 0% attendance or no attendance in inactive_weeks
 */
class ComputeMemberWelfare extends Command
{
    protected $signature = 'welfare:compute';

    protected $description = 'Recompute member welfare flags based on attendance patterns';

    public function handle(): int
    {
        $branches = Branch::where('is_active', true)->get();
        $totalUpdated = 0;

        foreach ($branches as $branch) {
            $windowWeeks = $branch->engagement_window_weeks ?? 4;
            $atRiskThreshold = $branch->at_risk_threshold_pct ?? 50;
            $inactiveWeeks = $branch->inactive_weeks ?? 6;

            $windowStart = now()->subWeeks($windowWeeks)->startOfWeek(Carbon::SUNDAY);
            $inactiveCutoff = now()->subWeeks($inactiveWeeks);

            // Total Sunday services in window
            $totalSundays = DB::table('attendance_sessions as s')
                ->join('service_types as st', 's.service_type_id', '=', 'st.id')
                ->where('s.branch_id', $branch->id)
                ->where('s.service_date', '>=', $windowStart)
                ->where(function ($q) {
                    $q->whereIn('st.slug', ['sunday_adult', 'sunday_children'])
                        ->orWhere(function ($q) {
                            $q->where('st.slug', 'cell_meeting')
                                ->whereRaw('EXTRACT(DOW FROM s.service_date) = 0');
                        });
                })
                ->selectRaw('COUNT(DISTINCT s.service_date) as count')
                ->value('count') ?? 1;

            $members = Member::where('branch_id', $branch->id)
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->get();

            foreach ($members as $member) {
                $attended = DB::table('attendance_records as ar')
                    ->join('attendance_sessions as s', 'ar.session_id', '=', 's.id')
                    ->join('service_types as st', 's.service_type_id', '=', 'st.id')
                    ->where('ar.member_id', $member->id)
                    ->where('ar.is_present', true)
                    ->whereNull('ar.deleted_at')
                    ->where('s.branch_id', $branch->id)
                    ->where('s.service_date', '>=', $windowStart)
                    ->where(function ($q) {
                        $q->whereIn('st.slug', ['sunday_adult', 'sunday_children'])
                            ->orWhere(function ($q) {
                                $q->where('st.slug', 'cell_meeting')
                                    ->whereRaw('EXTRACT(DOW FROM s.service_date) = 0');
                            });
                    })
                    ->count();

                $rate = $totalSundays > 0 ? ($attended / $totalSundays) * 100 : 0;

                $newFlag = match (true) {
                    $rate >= 75 => 'engaged',
                    $rate >= $atRiskThreshold => 'moderate',
                    $rate > 0 => 'at_risk',
                    default => 'inactive_risk',
                };

                // Last attendance date
                $lastDate = DB::table('attendance_records as ar')
                    ->join('attendance_sessions as s', 'ar.session_id', '=', 's.id')
                    ->where('ar.member_id', $member->id)
                    ->where('ar.is_present', true)
                    ->whereNull('ar.deleted_at')
                    ->where('s.branch_id', $branch->id)
                    ->max('s.service_date');

                if ($member->welfare_flag !== $newFlag || $member->last_attendance_date !== $lastDate) {
                    $member->update([
                        'welfare_flag' => $newFlag,
                        'last_attendance_date' => $lastDate,
                    ]);
                    $totalUpdated++;
                }
            }

            $this->info("Branch {$branch->name}: processed {$members->count()} members.");
        }

        $this->info("Done. Updated {$totalUpdated} member records.");

        return Command::SUCCESS;
    }
}
