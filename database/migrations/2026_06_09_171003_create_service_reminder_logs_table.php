<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_reminder_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();
            $table->foreignUuid('service_type_id')
                ->constrained('service_types')
                ->cascadeOnDelete();
            $table->foreignUuid('member_id')
                ->constrained('members')
                ->cascadeOnDelete();

            $table->timestamp('sent_at')->useCurrent();

            // The Sunday/Wednesday the reminder was FOR (used for idempotency
            // and for filtering "did we send the right reminder for this week").
            $table->date('intended_service_date');

            // status: 'sent' | 'no_phone' | 'failed'
            $table->string('status', 20);
            $table->string('phone_used', 30)->nullable();
            $table->text('message_body')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Idempotency check: "did this member already get the reminder
            // for THIS particular service date?"
            $table->index(['member_id', 'service_type_id', 'intended_service_date'], 'srl_idempotency_idx');

            // Audit queries: "show me everything sent this week"
            $table->index(['branch_id', 'sent_at']);

            // "Show me all reminders for next Sunday's service"
            $table->index(['branch_id', 'intended_service_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reminder_logs');
    }
};
