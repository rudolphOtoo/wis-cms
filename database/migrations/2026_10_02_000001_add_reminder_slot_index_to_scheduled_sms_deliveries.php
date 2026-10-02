<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index the service-reminder idempotency guard.
 *
 * Before a reminder is queued or sent, the collector and the hourly
 * fallback sender ask: "does this phone already have a reminder for this
 * calendar day?" That lookup runs once per (member × dispatch slot × day
 * in the window), so a 100-member branch over a 14-day window is well
 * over a thousand probes per sync.
 *
 * The existing indexes are all keyed on source_type + source_id, which is
 * the wrong axis for that question — the guard deliberately does NOT match
 * on source_id, because matching on it is what let two settings rows in the
 * same slot each conclude the slot was free. It needs to find any reminder
 * for this recipient on this date regardless of which automation created
 * it, so it must be indexed on (source_type, phone, scheduled_at, status).
 *
 * Column order matters. source_type and phone are equality constraints, then
 * scheduled_at is a range — and a range stops the index from narrowing on
 * anything after it, so `status` cannot act as a search key. It is carried
 * along anyway: the guard can then be satisfied from the index alone,
 * without touching the heap, on the large deliveries table where every
 * phone has accumulated rows from birthday greetings and broadcasts
 * alongside its reminders.
 *
 * Note the guard's date predicate must stay a bare comparison on
 * scheduled_at. whereDate() would compile to `scheduled_at::date = ?`, and
 * that cast demotes the range to a per-row filter, leaving the index able to
 * narrow only by source_type + phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_sms_deliveries', function (Blueprint $table) {
            $table->index(
                ['source_type', 'phone', 'scheduled_at', 'status'],
                'idx_sms_reminder_slot'
            );
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_sms_deliveries', function (Blueprint $table) {
            $table->dropIndex('idx_sms_reminder_slot');
        });
    }
};
