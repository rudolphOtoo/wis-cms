<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->uuid('member_id')->nullable();
            $table->uuid('child_id')->nullable();
            $table->boolean('is_present')->default(true);
            $table->timestamps();
            $table->foreign('session_id')->references('id')->on('attendance_sessions')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
            $table->foreign('child_id')->references('id')->on('children')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
