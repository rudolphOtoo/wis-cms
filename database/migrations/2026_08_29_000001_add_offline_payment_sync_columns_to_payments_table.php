<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Tracks whether a payment record was created during an
            // offline catch-up poll of Paystack (vs. recorded live by
            // the initialize/webhook flows). Values: 'local' (default)
            // or 'synced_from_remote'.
            $table->string('sync_status')->default('local')->after('status');

            // Offline SMS receipt flag. When the cloud relay is offline
            // (or a payment was made while the desktop PC was off), the
            // reconciliation command flags the payment here so the receipt
            // SMS flushes on the next boot / scheduled reconcile.
            $table->boolean('sms_pending')->default(false)->after('sync_status');

            // Set when the mNotify receipt SMS was actually dispatched.
            $table->timestamp('receipt_sms_sent_at')->nullable()->after('sms_pending');

            $table->index('sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['sync_status']);
            $table->dropColumn(['sync_status', 'sms_pending', 'receipt_sms_sent_at']);
        });
    }
};
