<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-channel delivery tracking on message_recipients to support
 * idempotent retry of broadcast message jobs.
 *
 * MOTIVATION
 * Before this change, SendBroadcastMessageJob caught all errors
 * and set the recipient to 'failed', meaning a transient SMS
 * provider hiccup (502, timeout) permanently dropped the message.
 * To enable retry, the job needs to be idempotent: a re-run must
 * skip work that already succeeded on a prior attempt.
 *
 * NEW COLUMNS
 *   email_sent_at  TIMESTAMP NULL  — when email delivery completed
 *   sms_sent_at    TIMESTAMP NULL  — when SMS delivery completed
 *   delivery_attempts INT NOT NULL DEFAULT 0  — retry counter for observability
 *
 * BACKFILL
 * For existing rows with delivery_status='delivered' and a
 * delivered_at timestamp, we conservatively assume both channels
 * succeeded (legacy data is single-pass; this prevents the next
 * job retry from re-sending old messages).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_recipients', function (Blueprint $table) {
            $table->timestamp('email_sent_at')->nullable()->after('delivered_at');
            $table->timestamp('sms_sent_at')->nullable()->after('email_sent_at');
            $table->unsignedInteger('delivery_attempts')->default(0)->after('sms_sent_at');
        });

        // Backfill: existing delivered rows get both channel timestamps
        // set to delivered_at (best-effort - legacy data is single-pass)
        DB::table('message_recipients')
            ->where('delivery_status', 'delivered')
            ->whereNotNull('delivered_at')
            ->update([
                'email_sent_at' => DB::raw('delivered_at'),
                'sms_sent_at' => DB::raw('delivered_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('message_recipients', function (Blueprint $table) {
            $table->dropColumn(['email_sent_at', 'sms_sent_at', 'delivery_attempts']);
        });
    }
};
