<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Self-service form submission. Lands here via the Google Form
 * webhook; admin approves into the members table or rejects.
 *
 * Untrusted input — never used for SMS dispatch directly.
 * Only Member records can receive SMS, broadcasts, etc.
 *
 * @property string $id
 * @property string $branch_id
 * @property string $first_name
 * @property string $last_name
 * @property string $phone
 * @property string|null $email
 * @property string|null $gender
 * @property Carbon|null $date_of_birth
 * @property string|null $address
 * @property string|null $occupation
 * @property string|null $marital_status
 * @property string|null $cell_name
 * @property string $status
 * @property string|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $review_notes
 * @property string|null $approved_member_id
 * @property Carbon $submitted_at
 * @property string|null $source_ip
 * @property array|null $raw_payload
 * @property string|null $idempotency_key
 * @property string $source
 */
class MemberSubmission extends Model
{
    use BelongsToBranch;
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const SOURCE_GOOGLE_FORM = 'google_form';

    public const SOURCE_WEB_FORM = 'web_form';

    public const SOURCE_MANUAL = 'manual';

    protected $table = 'member_submissions';

    protected $fillable = [
        'branch_id',
        'first_name',
        'last_name',
        'phone',
        'email',
        'gender',
        'date_of_birth',
        'address',
        'occupation',
        'marital_status',
        'cell_name',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'approved_member_id',
        'submitted_at',
        'source_ip',
        'raw_payload',
        'idempotency_key',
        'source',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    /**
     * Default attribute values applied by Eloquent on new model instances.
     * Belt-and-suspenders with the DB default: this guarantees the value
     * is set before INSERT regardless of how the model is created.
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
        'source' => self::SOURCE_GOOGLE_FORM,
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approvedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'approved_member_id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // QUERY SCOPES
    // ─────────────────────────────────────────────────────────────────────────

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ACCESSORS
    // ─────────────────────────────────────────────────────────────────────────

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DOMAIN HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Check whether a real Member with this phone already exists in the
     * same branch. Used by the admin UI and the approve() guard to flag
     * potential duplicates before promotion.
     */
    public function existingMemberWithSamePhone(): ?Member
    {
        return Member::where('branch_id', $this->branch_id)
            ->where('phone', $this->phone)
            ->first();
    }

    /**
     * Promote this submission to a real Member record.
     *
     * The entire operation runs inside a database transaction. If either
     * the Member upsert or the submission status update fails, the
     * database rolls back to a clean pre-call state — no orphaned Member
     * rows, no submission stuck in an ambiguous state.
     *
     * Phone-based upsert: if a Member with this phone already exists in
     * the branch the record is updated in place. If not, a new Member is
     * created. Either way the submission is marked approved and linked.
     *
     * @throws \Throwable Re-throws any exception that aborts the transaction.
     */
    public function promote(
        ?User $reviewer = null,
        ?string $cellId = null,
        ?string $notes = null,
    ): Member {
        return DB::transaction(function () use ($reviewer, $cellId, $notes): Member {
            $member = Member::updateOrCreate(
                [
                    'branch_id' => $this->branch_id,
                    'phone' => $this->phone,
                ],
                [
                    'first_name' => $this->first_name,
                    'last_name' => $this->last_name,
                    'gender' => $this->gender,
                    'date_of_birth' => $this->date_of_birth,
                    'email' => $this->email,
                    'address' => $this->address,
                    'occupation' => $this->occupation,
                    'marital_status' => $this->marital_status,
                    'cell_id' => $cellId,
                    'status' => 'active',
                    'is_baptised' => false,
                    'join_date' => now(),
                ]
            );

            $this->update([
                'status' => self::STATUS_APPROVED,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => now(),
                'review_notes' => $notes,
                'approved_member_id' => $member->id,
            ]);

            return $member;
        });
    }

    /**
     * Mark this submission as rejected with optional audit notes.
     */
    public function reject(?User $reviewer = null, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'reviewed_by' => $reviewer?->id,
            'reviewed_at' => now(),
            'review_notes' => $notes,
        ]);
    }
}
