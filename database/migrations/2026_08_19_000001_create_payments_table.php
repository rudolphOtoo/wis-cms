<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->uuid('member_id')->nullable();
            $table->string('payment_type');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('GHS');
            $table->string('channel');
            $table->string('momo_network')->nullable();
            $table->string('momo_number')->nullable();
            $table->string('status')->default('pending');
            $table->string('reference')->unique();
            $table->string('gateway_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->json('gateway_response')->nullable();
            $table->uuid('recorded_by_user_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('member_id')->references('id')->on('members')->nullOnDelete();
            $table->foreign('recorded_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['branch_id', 'status', 'payment_type']);
            $table->index('gateway_reference');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
