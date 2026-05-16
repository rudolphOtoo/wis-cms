<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('how_they_heard')->nullable();
            $table->date('visit_date');
            $table->enum('follow_up_status', ['pending', 'contacted', 'not_interested', 'joined'])->default('pending');
            $table->uuid('converted_member_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('converted_member_id')->references('id')->on('members')->nullOnDelete();
            $table->index(['branch_id', 'follow_up_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};
