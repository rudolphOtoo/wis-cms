<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance provenance + total-inclusion control.
 *
 * source            — 'manual' | 'biometric' | 'import'. Identifies how the
 *                     session's records were captured.
 * count_toward_total — when false, the session is EXCLUDED from church-wide
 *                     totals (stats, sundays, summary) while remaining visible
 *                     in per-scope listings. Lets ops park a session that
 *                     would double-count (e.g. a duplicate of a biometric
 *                     roster) without deleting data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('attendance_mode');
            $table->boolean('count_toward_total')->default(true)->after('source');
        });

        DB::statement(<<<'SQL'
            CREATE INDEX attendance_sessions_count_total_idx
                ON attendance_sessions (branch_id, service_date)
                WHERE count_toward_total = true
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendance_sessions_count_total_idx');

        Schema::table('attendance_sessions', function (Blueprint $table) {
            $table->dropColumn(['source', 'count_toward_total']);
        });
    }
};
