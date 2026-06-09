<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_reminder_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();
            $table->foreignUuid('service_type_id')
                ->constrained('service_types')
                ->cascadeOnDelete();

            $table->text('template');

            // Day-of-week the reminder fires (Postgres convention: 0=Sun..6=Sat).
            // For midweek service on Wednesday morning → 3.
            // For Sunday service reminder Saturday evening → 6.
            $table->smallInteger('send_day_of_week');

            // Hour-of-day the reminder fires (24h, 0-23).
            // Stored as integer because we only fire on the hour;
            // scheduler runs hourly and matches on day + hour.
            $table->smallInteger('send_hour');

            $table->foreignUuid('sender_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // One settings row per (branch, service_type) — each branch can
            // configure each of its service types independently.
            $table->unique(['branch_id', 'service_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_reminder_settings');
    }
};
