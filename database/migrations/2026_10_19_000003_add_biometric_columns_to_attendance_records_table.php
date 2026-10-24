<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Biometric ingestion columns + idempotent partial unique indexes.
 *
 * Snapshot columns (cell_id, department_id) are written at scan time so a
 * member moving cell later does NOT retroactively rewrite history — reports
 * attribute the attendance to the cell the member belonged to on the day.
 *
 * The two existing unique constraints are REPLACED with partial indexes that
 * ignore soft-deleted rows. The old constraints counted deleted rows, so a
 * member whose row was soft-deleted could never be re-scanned into the same
 * session.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('is_present');
            $table->uuid('biometric_device_id')->nullable()->after('source');
            $table->string('verification_mode', 30)->nullable()->after('biometric_device_id');
            $table->string('biometric_event_id')->nullable()->after('verification_mode');
            $table->uuid('cell_id')->nullable()->after('biometric_event_id');
            $table->uuid('department_id')->nullable()->after('cell_id');
            $table->timestamp('scanned_at')->nullable()->after('department_id');

            $table->foreign('biometric_device_id')->references('id')->on('biometric_devices')->nullOnDelete();
            $table->foreign('cell_id')->references('id')->on('cells')->nullOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });

        // Replace the all-rows unique constraints with soft-delete-aware
        // partial unique indexes. DROP CONSTRAINT handles the constraint form
        // Laravel's Postgres grammar creates; DROP INDEX is a no-op then.
        DB::statement('ALTER TABLE attendance_records DROP CONSTRAINT IF EXISTS attendance_records_session_member_unique');
        DB::statement('ALTER TABLE attendance_records DROP CONSTRAINT IF EXISTS attendance_records_session_child_unique');
        DB::statement('DROP INDEX IF EXISTS attendance_records_session_member_unique');
        DB::statement('DROP INDEX IF EXISTS attendance_records_session_child_unique');

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX attendance_records_session_member_unique
                ON attendance_records (session_id, member_id)
                WHERE deleted_at IS NULL AND member_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX attendance_records_session_child_unique
                ON attendance_records (session_id, child_id)
                WHERE deleted_at IS NULL AND child_id IS NOT NULL
        SQL);

        // Device event id: one row per device-reported event (retry backstop).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX attendance_records_biometric_event_unique
                ON attendance_records (biometric_event_id)
                WHERE biometric_event_id IS NOT NULL
        SQL);

        // Fast per-cell aggregation / dedupe support.
        DB::statement('CREATE INDEX attendance_records_source_idx ON attendance_records (source)');
        DB::statement('CREATE INDEX ar_session_cell_present_idx ON attendance_records (session_id, cell_id, is_present)');
        DB::statement('CREATE INDEX attendance_records_biometric_device_idx ON attendance_records (biometric_device_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS attendance_records_biometric_device_idx');
        DB::statement('DROP INDEX IF EXISTS ar_session_cell_present_idx');
        DB::statement('DROP INDEX IF EXISTS attendance_records_source_idx');
        DB::statement('DROP INDEX IF EXISTS attendance_records_biometric_event_unique');
        DB::statement('DROP INDEX IF EXISTS attendance_records_session_child_unique');
        DB::statement('DROP INDEX IF EXISTS attendance_records_session_member_unique');

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['biometric_device_id']);
            $table->dropForeign(['cell_id']);
            $table->dropForeign(['department_id']);
            $table->dropColumn([
                'source', 'biometric_device_id', 'verification_mode',
                'biometric_event_id', 'cell_id', 'department_id', 'scanned_at',
            ]);
        });

        // Restore the original all-rows unique constraints.
        DB::statement('ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_session_member_unique UNIQUE (session_id, member_id)');
        DB::statement('ALTER TABLE attendance_records ADD CONSTRAINT attendance_records_session_child_unique UNIQUE (session_id, child_id)');
    }
};
