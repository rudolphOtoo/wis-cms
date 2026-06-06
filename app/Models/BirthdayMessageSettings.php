<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BirthdayMessageSettings extends Model
{
    use BelongsToBranch;
    use HasUuids;

    protected $table = 'birthday_message_settings';

    protected $fillable = [
        'branch_id',
        'template',
        'sender_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The default template used when a branch has no settings row yet.
     * Placeholders: {first_name}, {church_name}
     */
    public const DEFAULT_TEMPLATE = 'Happy birthday {first_name}! {church_name} family is celebrating you today. May God bless your new year of life and grant you grace, health, and joy. — Your church family';

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Fetch the settings row for a branch, creating one with defaults
     * on first access. Always returns a real row — callers never have
     * to handle null.
     */
    public static function forBranch(string $branchId): self
    {
        return static::firstOrCreate(
            ['branch_id' => $branchId],
            [
                'template' => self::DEFAULT_TEMPLATE,
                'is_active' => true,
            ]
        );
    }

    /**
     * Render the template with member + branch values substituted.
     * Used by the scheduled sender and by the preview endpoint.
     */
    public function render(Member $member, ?string $churchName = null): string
    {
        return strtr($this->template, [
            '{first_name}' => $member->first_name,
            '{last_name}' => $member->last_name,
            '{full_name}' => trim("{$member->first_name} {$member->last_name}"),
            '{church_name}' => $churchName ?? $this->branch?->name ?? 'Your church',
        ]);
    }
}
