<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the cancelled_remote status to scheduled_sms_deliveries.
 *
 * Distinguishes "mNotify confirmed the remote job was cancelled or
 * defused" from the plain `cancelled` status (local-only cancellation,
 * e.g. expired while offline or never pushed to the provider).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE scheduled_sms_deliveries DROP CONSTRAINT IF EXISTS scheduled_sms_deliveries_status_check');
        DB::statement("ALTER TABLE scheduled_sms_deliveries ADD CONSTRAINT scheduled_sms_deliveries_status_check
            CHECK (status IN ('pending_api', 'scheduled_remote', 'dispatched', 'cancelled', 'cancelled_remote', 'failed'))");
    }

    public function down(): void
    {
        // Only possible when no rows use the new status.
        DB::statement('ALTER TABLE scheduled_sms_deliveries DROP CONSTRAINT IF EXISTS scheduled_sms_deliveries_status_check');
        DB::statement("ALTER TABLE scheduled_sms_deliveries ADD CONSTRAINT scheduled_sms_deliveries_status_check
            CHECK (status IN ('pending_api', 'scheduled_remote', 'dispatched', 'cancelled', 'failed'))");
    }
};
