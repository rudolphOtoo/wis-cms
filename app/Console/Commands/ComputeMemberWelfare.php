<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Member;
use App\Services\MemberWelfareService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Recomputes welfare_flag and last_attendance_date for all active members
 * across all branches. Scheduled weekly on Sunday evening after services.
 *
 * Welfare flag logic:
 *   - engaged:      attended >= 75% of services in window
 *   - moderate:     attended >= at_risk_threshold_pct (default 50%)
 *   - at_risk:      attended > 0% but < threshold
 *   - inactive_risk: 0% attendance or no attendance in inactive_weeks
 *
 * The command is orchestration only: every metric is resolved in one
 * aggregated query per branch by MemberWelfareService.
 */
class ComputeMemberWelfare extends Command
{
    protected $signature = 'welfare:compute';

    protected $description = 'Recompute member welfare flags based on attendance patterns';

    public function __construct(private readonly MemberWelfareService $welfare)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $branches = Branch::where('is_active', true)->get();
        $totalUpdated = 0;

        foreach ($branches as $branch) {
            $windowWeeks = $branch->engagement_window_weeks ?? 4;
            $atRiskThreshold = $branch->at_risk_threshold_pct ?? 50;

            $windowStart = now()->subWeeks($windowWeeks)->startOfWeek(Carbon::SUNDAY);

            // One aggregated query each for the whole branch.
            $totalSundays = $this->welfare->totalSundays($branch->id, $windowStart);
            $attendanceCounts = $this->welfare->attendanceCounts($branch->id, $windowStart);
            $lastAttendanceDates = $this->welfare->lastAttendanceDates($branch->id);

            $members = Member::where('branch_id', $branch->id)
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->get();

            foreach ($members as $member) {
                $attended = $attendanceCounts[$member->id] ?? 0;
                $rate = $totalSundays > 0 ? ($attended / $totalSundays) * 100 : 0;

                $newFlag = match (true) {
                    $rate >= 75 => 'engaged',
                    $rate >= $atRiskThreshold => 'moderate',
                    $rate > 0 => 'at_risk',
                    default => 'inactive_risk',
                };

                $lastDate = $lastAttendanceDates[$member->id] ?? null;

                $flagChanged = $member->welfare_flag !== $newFlag;
                $dateChanged = $member->last_attendance_date?->toDateString() !== $lastDate;

                if ($flagChanged || $dateChanged) {
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
