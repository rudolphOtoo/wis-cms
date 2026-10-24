<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Scopes\BranchScope;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves a fingerprint scan (device serial + device-local user id) to the
 * member it belongs to and the cell/department that member is assigned to.
 *
 * Purpose-built for the high-throughput scan endpoint: every lookup is
 * cached, so a warm terminal costs ZERO queries. Callers receive a plain
 * array DTO — no Eloquent objects leak onto the hot path.
 *
 * Resolution order for an enrollment:
 *   1. a device-scoped enrollment for this exact device (most specific), then
 *   2. a global enrollment (device_id IS NULL) for the branch.
 */
class BiometricMemberResolver
{
    public const CACHE_TTL_SECONDS = 600;

    /**
     * Resolve a scan to member + assignment attributes.
     *
     * @return array{
     *   device_id: string,
     *   branch_id: string,
     *   member_id: string,
     *   member_name: string,
     *   cell_id: string|null,
     *   cell_name: string|null,
     *   department_id: string|null,
     *   verification_mode: string,
     * }|null Null when the device or fingerprint is unknown.
     */
    public function resolve(string $deviceSerial, string $deviceUserId): ?array
    {
        $device = $this->resolveDevice($deviceSerial);

        if ($device === null) {
            return null;
        }

        $enrollment = $this->resolveEnrollment($device, $deviceUserId);

        if ($enrollment === null || $enrollment->member === null) {
            return null;
        }

        $member = $enrollment->member;

        return [
            'device_id' => $device->id,
            'branch_id' => $device->branch_id,
            'member_id' => $member->id,
            'member_name' => $member->full_name,
            'cell_id' => $member->cell_id,
            'cell_name' => $member->cell?->name,
            'department_id' => $member->departments->first()?->id,
            'verification_mode' => $enrollment->verification_mode,
        ];
    }

    /**
     * Resolve an active device by serial (cached, branch-unscoped system read).
     */
    public function resolveDevice(string $deviceSerial): ?BiometricDevice
    {
        return Cache::remember(
            "biometric:device:{$deviceSerial}",
            self::CACHE_TTL_SECONDS,
            fn () => BiometricDevice::withoutGlobalScope(BranchScope::class)
                ->where('serial_number', $deviceSerial)
                ->where('is_active', true)
                ->first(),
        );
    }

    /**
     * Resolve the enrollment for a device user id, preferring a
     * device-scoped row over a global one. Eager-loads the member's cell
     * and departments so the DTO can be built in memory.
     */
    protected function resolveEnrollment(BiometricDevice $device, string $deviceUserId): ?BiometricEnrollment
    {
        return Cache::remember(
            "biometric:enrollment:{$device->id}:{$deviceUserId}",
            self::CACHE_TTL_SECONDS,
            fn () => BiometricEnrollment::withoutGlobalScope(BranchScope::class)
                ->with(['member.cell', 'member.departments'])
                ->where('is_active', true)
                ->where('device_user_id', $deviceUserId)
                ->where(function ($q) use ($device) {
                    $q->where('device_id', $device->id)->orWhereNull('device_id');
                })
                // device_id IS NULL sorts FALSE (0) before TRUE (1): the
                // device-scoped enrollment wins.
                ->orderByRaw('device_id IS NULL')
                ->first(),
        );
    }

    /**
     * Drop a cached device + all its enrollment entries (call after
     * enrolling/re-enrolling or toggling a device).
     */
    public function forget(string $deviceSerial, ?string $deviceUserId = null): void
    {
        Cache::forget("biometric:device:{$deviceSerial}");

        if ($deviceUserId !== null) {
            $device = $this->resolveDevice($deviceSerial);
            if ($device !== null) {
                Cache::forget("biometric:enrollment:{$device->id}:{$deviceUserId}");
            }
        }
    }
}
