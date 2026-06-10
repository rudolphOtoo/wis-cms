<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Member submissions table — the staging area for self-service
     * form responses BEFORE they become real Members. Webhook lands
     * data here; admin reviews and approves into the members table.
     *
     * Rationale: members table is trusted (drives SMS dispatch, cell
     * assignment, reports). Untrusted external input goes here first.
     */
    public function up(): void
    {
        Schema::create('member_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            // === MEMBER-PROVIDED DATA (what they filled in) ===
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 30);
            $table->string('email')->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('address')->nullable();
            $table->string('occupation')->nullable();
            $table->string('marital_status', 20)->nullable();

            // What CELL the person says they belong to (free text from
            // form). Admin matches this to a real Cell during approval —
            // could be 'Bethel', 'Spintex Cell', 'Don't know', anything.
            $table->string('cell_name')->nullable();

            // === STATUS TRACKING ===
            // pending  → not yet reviewed
            // approved → promoted to members table
            // rejected → admin discarded
            $table->string('status', 20)->default('pending');

            // Audit: who acted on it, when, what they did
            $table->foreignUuid('reviewed_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // If approved, link to the Member row created
            $table->foreignUuid('approved_member_id')
                ->nullable()
                ->constrained('members')
                ->nullOnDelete();

            // === WEBHOOK METADATA ===
            $table->timestamp('submitted_at')->useCurrent();
            $table->string('source_ip', 45)->nullable();
            $table->jsonb('raw_payload')->nullable();

            $table->timestamps();

            // Indexes for queue browsing + dup detection
            $table->index(['branch_id', 'status', 'submitted_at'], 'msub_queue_idx');
            $table->index(['branch_id', 'phone'], 'msub_phone_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_submissions');
    }
};
