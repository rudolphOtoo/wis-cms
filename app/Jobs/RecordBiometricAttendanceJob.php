<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Scopes\BranchScope;
use App\Models\ServiceType;
use App\Services\BiometricMemberResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Materialises a biometric scan into attendance_records. Runs on the
 * dedicated `biometric` queue (see RecordBiometricAttendanceAction).
 *
 * Everything here is idempotent and lock-free:
 *   - the church-wide biometric session is firstOrCreate'd (unique-index
 *     backed) and re-fetched on a concurrent-insert race;
 *   - the record insert uses insertOrIgnore, so re-delivery is a no-op via
 *     the partial unique indexes on (session_id, member_id) and
 *     biometric_event_id.
 *
 * No row is ever UPDATEd and no FOR UPDATE lock is taken, so scan ingestion
 * never contends with reads or with cell leaders marking attendance.
 */
class RecordBiometricAttendanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    public function __construct(
        public string $memberId,
        public string $branchId,
        public ?string $cellId,
        public ?string $departmentId,
        public string $deviceId,
        public string $verificationMode,
        public ?string $eventId,
        public string $serviceDate,
        public string $scannedAt,
    ) {}

    public function handle(): void
    {
        $serviceTypeId = $this->resolveServiceTypeId();

        if ($serviceTypeId === null) {
            Log::warning('Biometric ingestion skipped: no active sunday_adult service type.');

            return;
        }

        $session = $this->resolveSession($serviceTypeId);

        // insertOrIgnore → INSERT ... ON CONFLICT DO NOTHING. Duplicate
        // scans (same session/member or same device event id) silently
        // collapse to the first row.
        DB::table('attendance_records')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'session_id' => $session->id,
            'member_id' => $this->memberId,
            'child_id' => null,
            'is_present' => true,
            'source' => AttendanceRecord::SOURCE_BIOMETRIC,
            'biometric_device_id' => $this->deviceId,
            'verification_mode' => $this->verificationMode,
            'biometric_event_id' => $this->eventId,
            'cell_id' => $this->cellId,
            'department_id' => $this->departmentId,
            'scanned_at' => $this->scannedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The single church-wide Sunday session that receives all entrance
     * scans for the day. Cell attribution lives on the record snapshot,
     * so one session per branch/date is sufficient and avoids row churn.
     */
    protected function resolveSession(string $serviceTypeId): AttendanceSession
    {
        $keys = [
            'branch_id' => $this->branchId,
            'service_type_id' => $serviceTypeId,
            'department_id' => null,
            'cell_id' => null,
            'service_date' => $this->serviceDate,
        ];

        $attributes = [
            'attendance_mode' => 'register',
            'source' => AttendanceSession::SOURCE_BIOMETRIC,
            'count_toward_total' => true,
        ];

        try {
            return AttendanceSession::withoutGlobalScope(BranchScope::class)
                ->firstOrCreate($keys, $attributes);
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker created the session first.
            return AttendanceSession::withoutGlobalScope(BranchScope::class)
                ->where($keys)
                ->firstOrFail();
        }
    }

    protected function resolveServiceTypeId(): ?string
    {
        return Cache::remember(
            'biometric:service_type:sunday_adult',
            BiometricMemberResolver::CACHE_TTL_SECONDS,
            fn () => ServiceType::where('slug', 'sunday_adult')
                ->where('is_active', true)
                ->value('id'),
        );
    }
}
