<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add failed_provider status and failure_reason column.
 *
 * The new status distinguishes provider-side rejections (e.g. insufficient
 * balance, rejected by carrier) from local failures (network exhausted,
 * API key missing). failure_reason stores the exact provider error string
 * for debugging and audit trails.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE scheduled_sms_deliveries DROP CONSTRAINT IF EXISTS scheduled_sms_deliveries_status_check');
        DB::statement("ALTER TABLE scheduled_sms_deliveries ADD CONSTRAINT scheduled_sms_deliveries_status_check
            CHECK (status IN ('pending_api', 'scheduled_remote', 'dispatched', 'cancelled', 'cancelled_remote', 'failed', 'failed_provider'))");

        Schema::table('scheduled_sms_deliveries', function ($table) {
            $table->text('failure_reason')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_sms_deliveries', function ($table) {
            $table->dropColumn('failure_reason');
        });

        DB::statement('ALTER TABLE scheduled_sms_deliveries DROP CONSTRAINT IF EXISTS scheduled_sms_deliveries_status_check');
        DB::statement("ALTER TABLE scheduled_sms_deliveries ADD CONSTRAINT scheduled_sms_deliveries_status_check
            CHECK (status IN ('pending_api', 'scheduled_remote', 'dispatched', 'cancelled', 'cancelled_remote', 'failed'))");
    }
};
