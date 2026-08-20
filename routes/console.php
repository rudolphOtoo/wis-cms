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

// Architecture audit: schema FK columns vs Eloquent relationships.
// Catches missing belongsTo methods - the recurring bug class where
// a FK column exists but the relationship method was never added,
// causing Eloquent to silently return NULL on access. Runs Mondays
// at 08:30 alongside the leader audit. Manual: php artisan app:audit-fk-relationships
Schedule::command('app:audit-fk-relationships')->weeklyOn(1, '08:30');

// Council-requested feature: automated post-meeting SMS follow-up.
// Runs every 15 minutes, dispatches sessions ripe for sending
// (branch.follow_up_enabled = true AND now >= created_at + delay_hours).
// Idempotent; safe to re-run.
Schedule::command('attendance:process-follow-ups')->everyFifteenMinutes();

// Council-requested feature: pre-service SMS reminders. Runs every
// hour on the hour. The command itself filters by the configured
// day-of-week + hour for each ServiceReminderSettings row, so most
// hourly runs are no-ops. Configured slots (e.g. Saturday 20:00 for
// Sunday service, Wednesday 09:00 for midweek service) trigger the
// actual fan-out to all active branch members. Idempotent: a member
// is never sent the same reminder twice for the same service date.
Schedule::command('reminders:send')->hourly();

// Weekly welfare flag recomputation — runs Sunday evening after services.
// Computes attendance-based welfare flags for all active members per branch.
Schedule::command('welfare:compute')->weeklyOn(0, '20:00');

// Retry failed mNotify API calls from the offline pending queue.
// Runs every 5 minutes; idempotent and safe to re-run. Picks up
// scheduled SMS that couldn't be pushed to mNotify due to network
// issues and retries them.
Schedule::command('sync:pending-schedules')->everyFiveMinutes();

// Rolling pre-scheduler: push dynamic SMS automations (birthdays,
// service reminders) to mNotify's remote queue for offline resilience.
// Runs daily at 05:00, before the 07:00 birthday send. Supplemented
// by the entrypoint.sh boot-time run which ensures14 days are always
// queued after a container restart.
Schedule::command('sms:sync-rolling-automations')->dailyAt('05:00');
