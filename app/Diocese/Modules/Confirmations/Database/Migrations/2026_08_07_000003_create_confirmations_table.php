<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmations ledger. Belongs to the Confirmations module and is only
 * migrated when `capabilities.modules.confirmations` is enabled in the
 * active profile (the module provider is not registered otherwise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('confirmations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('member_id')->constrained()->cascadeOnDelete();
            $table->uuid('recorded_by_user_id')->nullable()->constrained('users');
            $table->date('confirmed_at');
            $table->string('officiating_clergy')->nullable();
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'confirmed_at']);
            $table->index('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('confirmations');
    }
};
