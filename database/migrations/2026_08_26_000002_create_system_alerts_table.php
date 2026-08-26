<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent admin-facing system alerts.
 *
 * Used by the SMS credit guard and reconciliation engine to surface
 * critical operational failures (e.g. insufficient mNotify credits)
 * directly in the admin dashboard.
 *
 * Unlike ephemeral Sonner toasts, these survive page refreshes and
 * require explicit acknowledgement by an admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // credit_depletion, reconciliation, general
            $table->string('title');
            $table->text('message');
            $table->json('meta')->nullable(); // optional structured data
            $table->timestamp('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_alerts');
    }
};
