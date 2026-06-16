<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-07 / SECURITY: Add SoftDeletes to attendance_records.
 *
 * Unlike transactions and members (which already have soft-deletes),
 * attendance_records were permanently destroyed on delete. This meant:
 *  - A mis-marked session could not be undone without re-entry.
 *  - No audit trail for who was marked absent vs. not recorded.
 *
 * SoftDeletes allows the admin to restore a mis-deleted record, and
 * keeps the deleted_at column visible in the activity log.
 *
 * NOTE: After running this migration, the AttendanceRecord model must
 * use the SoftDeletes trait, and any raw queries on attendance_records
 * must add WHERE deleted_at IS NULL explicitly (Eloquent does this
 * automatically for model queries; DB::table() does not).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
