<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biometric fingerprint/card scanners deployed at church entrances.
 *
 * A device belongs to exactly one branch (multi-tenant boundary) and is
 * identified over the wire by its serial_number. The shared ingest secret
 * is stored hashed in secret_hash; the plaintext lives only in the device
 * configuration / .env (see services.biometric.webhook_secret).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('branch_id');
            $table->string('name');
            $table->string('serial_number')->unique();
            $table->string('location')->nullable();
            $table->string('secret_hash')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->index('branch_id');
            $table->index(['branch_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};
