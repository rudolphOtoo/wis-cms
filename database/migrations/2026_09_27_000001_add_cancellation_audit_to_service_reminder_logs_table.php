<?php

use App\Models\ServiceReminderLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen service_reminder_logs from a send log into a full audit trail.
 *
 * A withdrawal is an outcome a member-visible SMS can have, so the admin
 * screen can clear the message from the live list and still show that
 * somebody took it away, when, and on whose authority.
 *
 * member_id / service_type_id / intended_service_date become nullable
 * because a whole-automation cancel describes every message withdrawn at
 * once: it belongs to no single member and no single service date. A
 * per-message cancellation resolves all three, but it cannot be
 * guaranteed to — a number may match no member, and a birthday dispatch
 * has no service type at all.
 *
 * The two foreign keys move from CASCADE to SET NULL for the same
 * reason. An audit record that disappears the moment the member it is
 * about, or the service type it belongs to, is deleted is not an audit
 * record. The number that was texted and the wording of the entry both
 * survive on the row itself, so the entry stays readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reminder_logs', function (Blueprint $table) {
            // Explicit drop/re-add: Postgres cannot relax a NOT NULL column
            // while a foreign key still references it.
            $table->dropForeign(['service_type_id']);
            $table->dropForeign(['member_id']);

            $table->foreignUuid('service_type_id')->nullable()->change();
            $table->foreignUuid('member_id')->nullable()->change();
            $table->date('intended_service_date')->nullable()->change();

            $table->foreign('service_type_id')->references('id')->on('service_types')->nullOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();

            // Who took the message away, and what the entry means.
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('detail')->nullable();
        });
    }

    public function down(): void
    {
        // Cancellation entries cannot be represented by the original
        // NOT NULL schema, so they are dropped before the constraints
        // go back on.
        DB::table('service_reminder_logs')
            ->whereIn('status', [
                ServiceReminderLog::STATUS_CANCELLED,
                ServiceReminderLog::STATUS_CANCELLED_BATCH,
            ])
            ->delete();

        Schema::table('service_reminder_logs', function (Blueprint $table) {
            $table->dropForeign(['service_type_id']);
            $table->dropForeign(['member_id']);
            $table->dropColumn(['cancelled_by', 'detail']);

            $table->foreignUuid('service_type_id')->nullable(false)->change();
            $table->foreignUuid('member_id')->nullable(false)->change();
            $table->date('intended_service_date')->nullable(false)->change();

            $table->foreign('service_type_id')->references('id')->on('service_types')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }
};
