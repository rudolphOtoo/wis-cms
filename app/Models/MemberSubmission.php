<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Self-service form submission. Lands here via the Google Form
 * webhook; admin approves into the members table or rejects.
 *
 * Untrusted input — never used for SMS dispatch directly.
 * Only Member records can receive SMS, broadcasts, etc.
 */
class MemberSubmission extends Model
{
    use BelongsToBranch;
    use HasUuids;

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
        'status' => 'pending',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

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

    /**
     * Convenience: full name for display in admin queue.
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Check whether a submission with this phone already exists
     * as a real Member. Used by admin UI to flag potential
     * duplicates before approval.
     */
    public function existingMemberWithSamePhone(): ?Member
    {
        return Member::where('branch_id', $this->branch_id)
            ->where('phone', $this->phone)
            ->first();
    }

    /**
     * Promote this submission to a real Member record. Marks
     * the submission as approved and links the new Member.
     *
     * Phone-based upsert: if a Member with this phone already
     * exists in the branch, updates it; otherwise creates new.
     */
    public function promote(?User $reviewer = null, ?string $cellId = null, ?string $notes = null): Member
    {
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
    }

    /**
     * Mark this submission as rejected with optional notes.
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
