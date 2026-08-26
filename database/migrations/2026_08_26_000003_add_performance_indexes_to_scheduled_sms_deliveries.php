<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add composite indexes for reconciliation query performance.
 *
 * The reconciliation command queries `WHERE status IN (...) AND scheduled_at <= now()`.
 * Without a composite index, this requires a full table scan on large datasets.
 *
 * Also adds an index on mnotify_job_id for O(1) lookup during delivery report matching.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_sms_deliveries', function (Blueprint $table) {
            // Composite index for reconciliation queries: status + scheduled_at
            $table->index(['status', 'scheduled_at'], 'idx_sms_status_scheduled');

            // Composite index for source deduplication: source_type + source_id + status
            $table->index(['source_type', 'source_id', 'status'], 'idx_sms_source_status');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_sms_deliveries', function (Blueprint $table) {
            $table->dropIndex('idx_sms_status_scheduled');
            $table->dropIndex('idx_sms_source_status');
        });
    }
};
