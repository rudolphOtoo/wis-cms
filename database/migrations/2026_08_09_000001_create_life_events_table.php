<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('life_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id')->constrained();
            $table->uuid('recorded_by_user_id')->constrained('users');
            // 'death' records a deceased member (linked via member_id);
            // 'birth' records a newborn (baby + mother details, optional member link).
            $table->enum('type', ['death', 'birth']);
            $table->date('event_date');
            $table->uuid('member_id')->nullable()->constrained()->nullOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('mother_first_name')->nullable();
            $table->string('mother_last_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['branch_id', 'type', 'event_date']);
            $table->index(['member_id', 'event_date']);
            $table->index(['type', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('life_events');
    }
};
