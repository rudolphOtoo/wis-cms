<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_reminder_settings', function (Blueprint $table) {
            // Hour-of-day the SERVICE itself runs (24h, 0-23). Separate from
            // send_hour — the send_hour is WHEN the reminder fires, this is
            // the time the SERVICE actually starts. Stored as an integer
            // hour because Ghana churches don't typically schedule services
            // at fractional times like 9:15 AM. Used in template via
            // {service_time} placeholder.
            $table->smallInteger('service_hour')
                ->default(9)
                ->after('send_hour');

            // Minute-of-hour for service start (0-59). Defaults to 0 so a
            // Sunday service can be 9:00 AM and a midweek service 6:30 PM.
            $table->smallInteger('service_minute')
                ->default(0)
                ->after('service_hour');
        });
    }

    public function down(): void
    {
        Schema::table('service_reminder_settings', function (Blueprint $table) {
            $table->dropColumn(['service_hour', 'service_minute']);
        });
    }
};
