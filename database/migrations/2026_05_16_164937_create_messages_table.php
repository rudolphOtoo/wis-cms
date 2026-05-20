<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('sender_id')->nullable();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->enum('channel', ['sms', 'email', 'both']);
            $table->enum('status', ['draft', 'sending', 'sent', 'failed'])->default('draft');
            $table->string('recipient_group')->nullable();
            $table->uuid('department_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('sender_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
