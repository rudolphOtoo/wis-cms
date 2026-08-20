<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks SMS messages scheduled via mNotify's remote scheduling API.
 *
 * When the local CMS schedules an SMS for future delivery, the mNotify
 * job ID is stored here so the CMS can later cancel or update it, and
 * so admins can see what's queued on the remote provider.
 *
 * STATUS LIFECYCLE
 *   pending_api       → record created, waiting to be pushed to mNotify
 *   scheduled_remote  → mNotify accepted the job; will execute at scheduled_at
 *   dispatched        → mNotify executed the SMS (confirmed or assumed after scheduled_at)
 *   cancelled        → admin cancelled via CMS; mNotify was notified
 *   failed           → mNotify rejected or network failure exhausted retries
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_sms_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('mnotify_job_id')->nullable()->index();
            $table->string('phone');
            $table->text('message_body');
            $table->timestamp('scheduled_at');
            $table->enum('status', [
                'pending_api',
                'scheduled_remote',
                'dispatched',
                'cancelled',
                'failed',
            ])->default('pending_api');
            $table->string('source_type')->nullable(); // birthday, reminder, follow_up, ad_hoc
            $table->uuid('source_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->text('error_message')->nullable();
            $table->json('mnotify_response')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->index('status');
            $table->index('scheduled_at');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_sms_deliveries');
    }
};
