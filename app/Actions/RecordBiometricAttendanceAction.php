<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\RecordBiometricAttendanceJob;
use App\Services\BiometricMemberResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Hot path for biometric scan ingestion.
 *
 * Target: sub-50ms. To get there this action performs NO database writes and
 * takes NO row locks — only cached lookups plus one atomic Redis
 * `SET NX EX` (Cache::add) for duplicate suppression. Session creation and
 * the attendance insert are handed to RecordBiometricAttendanceJob on the
 * dedicated `biometric` queue.
 *
 * Idempotency (5-second window): Cache::add is atomic, so two rapid scans of
 * the same finger cannot both enqueue. The device event id and the partial
 * unique index on (session_id, member_id) are the durable backstops in the
 * worker.
 */
class RecordBiometricAttendanceAction
{
    /** Duplicate scans of the same member within this window are dropped. */
    public const DEDUPE_WINDOW_SECONDS = 5;

    public function __construct(private readonly BiometricMemberResolver $resolver) {}

    /**
     * @param  array{device_serial: string, device_user_id: string, verification_mode?: string|null, event_id?: string|null, scanned_at?: string|null}  $payload
     * @return array{status: 'recorded'|'duplicate'|'unknown', member: array|null}
     */
    public function execute(array $payload): array
    {
        $scannedAt = isset($payload['scanned_at'])
            ? CarbonImmutable::parse($payload['scanned_at'])
            : CarbonImmutable::now();

        $member = $this->resolver->resolve(
            (string) $payload['device_serial'],
            (string) $payload['device_user_id'],
        );

        if ($member === null) {
            return ['status' => 'unknown', 'member' => null];
        }

        $serviceDate = $scannedAt->toDateString();
        $dedupeKey = "biometric:scan:{$member['member_id']}:{$serviceDate}";

        // Atomic Redis SET NX EX. Returns false when the key already exists,
        // i.e. a scan for this member already arrived within the window.
        if (! Cache::add($dedupeKey, true, self::DEDUPE_WINDOW_SECONDS)) {
            return ['status' => 'duplicate', 'member' => $member];
        }

        try {
            RecordBiometricAttendanceJob::dispatch(
                $member['member_id'],
                $member['branch_id'],
                $member['cell_id'],
                $member['department_id'],
                $member['device_id'],
                $member['verification_mode'] ?? 'fingerprint',
                $payload['event_id'] ?? null,
                $serviceDate,
                $scannedAt->toDateTimeString(),
            )->onQueue('biometric');
        } catch (\Throwable $e) {
            // Release the dedupe key so the member's next scan retries.
            Cache::forget($dedupeKey);
            Log::error('Biometric scan enqueue failed', [
                'member_id' => $member['member_id'],
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return ['status' => 'recorded', 'member' => $member];
    }
}
