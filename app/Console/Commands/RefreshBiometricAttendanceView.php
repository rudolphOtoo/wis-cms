<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes mv_biometric_attendance_daily.
 *
 * Uses REFRESH ... CONCURRENTLY (readers are never blocked) once the view has
 * been populated at least once; falls back to a plain refresh for the initial
 * population (CONCURRENTLY requires a populated view). Scheduled frequently
 * during service windows so reporting stays fresh without blocking scans.
 */
class RefreshBiometricAttendanceView extends Command
{
    protected $signature = 'biometric:refresh-view
                            {--force : Refresh even when the view is already populated}';

    protected $description = 'Refresh the mv_biometric_attendance_daily materialized view';

    public function handle(): int
    {
        if (! $this->viewExists()) {
            $this->error('Materialized view mv_biometric_attendance_daily does not exist.');

            return Command::FAILURE;
        }

        if (! $this->isPopulated() || $this->option('force')) {
            DB::statement('REFRESH MATERIALIZED VIEW mv_biometric_attendance_daily');
            $this->info('Biometric attendance view refreshed (initial population).');

            return Command::SUCCESS;
        }

        try {
            DB::statement('REFRESH MATERIALIZED VIEW CONCURRENTLY mv_biometric_attendance_daily');
            $this->info('Biometric attendance view refreshed concurrently.');
        } catch (\Throwable $e) {
            // CONCURRENTLY cannot run inside a transaction (e.g. test setup).
            Log::warning('Concurrent refresh failed; falling back to blocking refresh.', [
                'error' => $e->getMessage(),
            ]);
            DB::statement('REFRESH MATERIALIZED VIEW mv_biometric_attendance_daily');
            $this->info('Biometric attendance view refreshed (blocking fallback).');
        }

        return Command::SUCCESS;
    }

    protected function viewExists(): bool
    {
        return DB::table('pg_class')
            ->where('relname', 'mv_biometric_attendance_daily')
            ->where('relkind', 'm')
            ->exists();
    }

    protected function isPopulated(): bool
    {
        return (bool) DB::table('pg_class')
            ->where('relname', 'mv_biometric_attendance_daily')
            ->value('relispopulated');
    }
}
