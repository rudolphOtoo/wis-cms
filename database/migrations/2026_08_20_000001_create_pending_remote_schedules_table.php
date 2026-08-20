<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline-resilience queue for mNotify API calls.
 *
 * If the church desktop loses internet while an admin schedules an SMS
 * (or the automated sync can't reach mNotify), the request is stored
 * here. A background command (sync:pending-schedules) retries pending
 * items when connectivity is restored.
 *
 * STATUS LIFECYCLE
 *   pending    → waiting to be pushed (default)
 *   processing → a worker has picked it up and is attempting the API call
 *   completed  → mNotify accepted the request
 *   failed     → exhausted all retries (manual intervention needed)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_remote_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action'); // schedule, cancel, update
            $table->uuid('scheduled_sms_delivery_id')->nullable();
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('last_attempt_at')->nullable();
            $table->enum('status', [
                'pending',
                'processing',
                'completed',
                'failed',
            ])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->foreign('scheduled_sms_delivery_id')
                ->references('id')->on('scheduled_sms_deliveries')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_remote_schedules');
    }
};
