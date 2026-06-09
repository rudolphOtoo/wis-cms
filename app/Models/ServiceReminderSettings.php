<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Settings for the recurring per-service-type SMS reminder.
 *
 * One row per (branch, service_type). Each row says: for THIS branch's
 * THIS service type, fire the configured template at the configured
 * day-of-week and hour. The scheduled command reads these rows hourly
 * and sends to all active members of the branch.
 */
class ServiceReminderSettings extends Model
{
    use BelongsToBranch;
    use HasUuids;

    protected $table = 'service_reminder_settings';

    protected $fillable = [
        'branch_id',
        'service_type_id',
        'template',
        'send_day_of_week',
        'send_hour',
        'service_hour',
        'service_minute',
        'sender_id',
        'is_active',
    ];

    protected $casts = [
        'send_day_of_week' => 'integer',
        'send_hour' => 'integer',
        'service_hour' => 'integer',
        'service_minute' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Default template for a Sunday Adult Service reminder (fires
     * Saturday evening). Placeholders: {first_name}, {service_name},
     * {service_time}, {church_name}.
     */
    public const DEFAULT_SUNDAY_TEMPLATE = 'Good evening {first_name}! Sunday service is tomorrow at {service_time} at {church_name}. Looking forward to worshipping with you. God bless. — Your church family';

    /**
     * Default template for the midweek service reminder (fires
     * Wednesday morning). The "tonight" wording is intentional —
     * fires the same day as the service.
     */
    public const DEFAULT_MIDWEEK_TEMPLATE = 'Hello {first_name}! Reminder: midweek service tonight at {service_time}. Come refresh in His presence. {church_name} family looking forward to you. — Pastor';

    /**
     * Generic fallback for any other service type added later.
     */
    public const DEFAULT_GENERIC_TEMPLATE = 'Hello {first_name}! Reminder: {service_name} on {service_date} at {service_time}. {church_name} looking forward to seeing you. God bless.';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Pick a sensible default template based on the service type slug.
     * Used when seeding a new settings row.
     */
    public static function defaultTemplateFor(string $serviceTypeSlug): string
    {
        return match ($serviceTypeSlug) {
            'sunday_adult' => self::DEFAULT_SUNDAY_TEMPLATE,
            'bible_study', 'prayer_meeting' => self::DEFAULT_MIDWEEK_TEMPLATE,
            default => self::DEFAULT_GENERIC_TEMPLATE,
        };
    }

    /**
     * Render the template with member + service + branch values.
     * If $serviceTime isn't passed, falls back to the configured
     * service_hour:service_minute on the settings row.
     */
    public function render(
        Member $member,
        ?string $serviceName = null,
        ?Carbon $serviceDate = null,
        ?string $serviceTime = null,
        ?string $churchName = null,
    ): string {
        $date = $serviceDate ?? now();

        return strtr($this->template, [
            '{first_name}' => $member->first_name,
            '{last_name}' => $member->last_name,
            '{full_name}' => trim("{$member->first_name} {$member->last_name}"),
            '{service_name}' => $serviceName ?? $this->serviceType?->name ?? 'Service',
            '{service_date}' => $date->format('l j F'),
            '{service_time}' => $serviceTime ?? $this->serviceTimeLabel(),
            '{church_name}' => $churchName ?? $this->branch?->name ?? 'Your church',
        ]);
    }

    /**
     * Human-readable day name for the configured send_day_of_week.
     */
    public function dayName(): string
    {
        return [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ][$this->send_day_of_week] ?? 'Unknown';
    }

    /**
     * Human-readable time label for the configured send_hour.
     */
    public function hourLabel(): string
    {
        return $this->formatHourMinute((int) $this->send_hour, 0);
    }

    /**
     * Human-readable time the SERVICE itself starts. Used in the SMS via
     * {service_time}. Example: "9:00 AM", "6:30 PM".
     */
    public function serviceTimeLabel(): string
    {
        return $this->formatHourMinute(
            (int) $this->service_hour,
            (int) $this->service_minute,
        );
    }

    /**
     * Format an (hour, minute) pair as a 12-hour clock string.
     */
    protected function formatHourMinute(int $h, int $m): string
    {
        $mm = str_pad((string) $m, 2, '0', STR_PAD_LEFT);
        if ($h === 0) {
            return "12:{$mm} AM";
        }
        if ($h === 12) {
            return "12:{$mm} PM";
        }
        if ($h < 12) {
            return "{$h}:{$mm} AM";
        }

        return ($h - 12).":{$mm} PM";
    }
}
