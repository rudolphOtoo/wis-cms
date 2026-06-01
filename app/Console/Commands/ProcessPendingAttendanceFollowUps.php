<?php

namespace App\Console\Commands;

use App\Jobs\DispatchAttendanceFollowUpJob;
use App\Models\AttendanceSession;
use Illuminate\Console\Command;

/**
 * Scheduler entry point for the automated post-meeting follow-up.
 *
 * Runs every 15 minutes via routes/console.php. For each cell or
 * department attendance session that meets all conditions:
 *   - follow_up_status = 'not_sent'
 *   - branch.follow_up_enabled = true
 *   - now() >= session.created_at + (branch.follow_up_delay_hours hours)
 *
 * ...dispatches DispatchAttendanceFollowUpJob, which handles the
 * lock-and-send dance per session.
 *
 * Idempotent: re-running is safe. Sessions in 'sending' / 'sent' /
 * 'failed' / 'disabled' are skipped.
 *
 * Manual run (testing): php artisan attendance:process-follow-ups
 */
class ProcessPendingAttendanceFollowUps extends Command
{
    protected $signature = 'attendance:process-follow-ups
                            {--dry-run : Show what would be dispatched without queueing}';

    protected $description = 'Dispatch post-meeting follow-up SMS for sessions past their delay window';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('Scanning for pending attendance follow-ups...');

        // Join branches so we can compare per-branch follow_up_delay_hours.
        // Only consider sessions on enabled branches.
        $candidates = AttendanceSession::query()
            ->join('branches', 'attendance_sessions.branch_id', '=', 'branches.id')
            ->where('attendance_sessions.follow_up_status', 'not_sent')
            ->where('branches.follow_up_enabled', true)
            // PostgreSQL interval syntax: created_at + N hours <= now()
            ->whereRaw('attendance_sessions.created_at + (branches.follow_up_delay_hours || \' hours\')::interval <= now()')
            ->select('attendance_sessions.id', 'attendance_sessions.created_at', 'attendance_sessions.cell_id', 'attendance_sessions.department_id', 'branches.follow_up_delay_hours')
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('  No pending follow-ups. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info("  Found {$candidates->count()} session(s) ready to send.");

        foreach ($candidates as $row) {
            $unit = $row->cell_id ? 'cell' : ($row->department_id ? 'department' : 'service');
            $line = "    - Session {$row->id} ({$unit}) created {$row->created_at}, delay {$row->follow_up_delay_hours}h";

            if ($dryRun) {
                $this->line($line.'  [DRY RUN - not dispatched]');

                continue;
            }

            DispatchAttendanceFollowUpJob::dispatch($row->id);
            $this->line($line.'  [dispatched]');
        }

        if (! $dryRun) {
            activity()->log("Attendance follow-up: dispatched jobs for {$candidates->count()} session(s)");
        }

        return self::SUCCESS;
    }
}
