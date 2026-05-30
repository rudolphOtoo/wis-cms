<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

// Daily birthday greetings — 07:00 server time. Honors config/church.php
// (BIRTHDAY_GREETINGS_ENABLED). Requires a cron entry running
// `php artisan schedule:run` every minute in production.
Schedule::command('birthdays:send')->dailyAt('07:00');

// Architecture review Step 7: weekly audit for orphan leaders
// (users with member-tied roles but no linked Member). In a healthy
// system this always reports zero; alerts catch silent data drift.
Schedule::command('app:audit-unlinked-leaders')->weeklyOn(1, '08:00');
