<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_message_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();
            $table->foreignUuid('member_id')
                ->constrained('members')
                ->cascadeOnDelete();

            $table->timestamp('sent_at')->useCurrent();

            // status: 'sent' | 'no_phone' | 'failed'
            // Using string instead of enum so it's easy to extend later.
            $table->string('status', 20);

            $table->string('phone_used', 30)->nullable();
            $table->text('message_body')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            // Common queries:
            //   "Did this branch send anything today" → (branch_id, sent_at)
            //   "Did this member already get a message today" → (member_id, sent_at)
            $table->index(['branch_id', 'sent_at']);
            $table->index(['member_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_message_logs');
    }
};
