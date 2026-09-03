<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentChannel;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use BelongsToBranch, HasFactory, HasUuids, SoftDeletes;

    /** Recorded live through the app's initialize/webhook/verify flows. */
    public const SYNC_STATUS_LOCAL = 'local';

    /** Backfilled by the Paystack cold-boot reconciliation poll. */
    public const SYNC_STATUS_SYNCED_FROM_REMOTE = 'synced_from_remote';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'branch_id',
        'member_id',
        'payment_type',
        'amount',
        'currency',
        'channel',
        'momo_network',
        'momo_number',
        'status',
        'sync_status',
        'sms_pending',
        'receipt_sms_sent_at',
        'reference',
        'gateway_reference',
        'metadata',
        'gateway_response',
        'recorded_by_user_id',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_type' => PaymentType::class,
            'channel' => PaymentChannel::class,
            'status' => PaymentStatus::class,
            'amount' => 'decimal:2',
            'metadata' => 'array',
            'gateway_response' => 'array',
            'sync_status' => 'string',
            'sms_pending' => 'boolean',
            'receipt_sms_sent_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    // ── Relationships ───────────────────────────────────────────────────

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    // ── Scopes ──────────────────────────────────────────────────────────

    public function scopeByStatus($query, PaymentStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByType($query, PaymentType $type)
    {
        return $query->where('payment_type', $type);
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', PaymentStatus::Success);
    }

    // ── Transaction Bridge ──────────────────────────────────────────────

    /**
     * Create a corresponding Transaction record from a successful payment.
     *
     * Looks up the finance category by payment_type mapping, then creates
     * an income transaction that integrates with the existing finance ledger,
     * reports, and exports.
     *
     * IDEMPOTENT: exactly one income ledger entry is ever created per payment
     * reference. This is the guard that guarantees "zero duplicate crediting"
     * when the webhook, the verify endpoint, and the Paystack reconciliation
     * poll all race to record the same successful payment.
     */
    public function createTransactionFromPayment(): Transaction
    {
        $existing = Transaction::query()
            ->where('reference', $this->reference)
            ->where('type', 'income')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $category = FinanceCategory::query()
            ->where('payment_type', $this->payment_type->value)
            ->where('type', 'income')
            ->where('is_active', true)
            ->first();

        return Transaction::create([
            'branch_id' => $this->branch_id,
            'category_id' => $category?->id,
            'member_id' => $this->member_id,
            'type' => 'income',
            'amount' => $this->amount,
            'currency' => $this->currency,
            'transaction_date' => $this->paid_at?->toDateString() ?? now()->toDateString(),
            'reference' => $this->reference,
            'notes' => "Online {$this->payment_type->label()} via {$this->channel->label()} — ref: {$this->reference}",
            'recorded_by' => $this->recorded_by_user_id,
        ]);
    }
}
