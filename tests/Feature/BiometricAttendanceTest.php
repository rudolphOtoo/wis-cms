<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RecordBiometricAttendanceJob;
use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Branch;
use App\Models\Cell;
use App\Models\Member;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\BiometricAttendanceService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Fingerprint biometric attendance ingestion end-to-end:
 * scan endpoint -> action (atomic dedupe) -> queued job -> attendance_records,
 * plus the cell-aggregated reporting read model.
 */
class BiometricAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret = 'test-biometric-secret';

    protected Branch $branch;

    protected BiometricDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        config(['services.biometric.webhook_secret' => $this->secret]);

        $this->branch = Branch::factory()->create();

        ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            ['name' => 'Sunday Adult Service', 'type' => 'adult', 'is_active' => true],
        );

        $this->device = BiometricDevice::create([
            'branch_id' => $this->branch->id,
            'name' => 'Main Entrance',
            'serial_number' => 'DEV-001',
            'is_active' => true,
        ]);
    }

    protected function makeMemberWithCell(string $cellName, string $deviceUserId): array
    {
        $cell = Cell::create([
            'branch_id' => $this->branch->id,
            'name' => $cellName,
            'is_active' => true,
        ]);

        $member = Member::factory()->create([
            'branch_id' => $this->branch->id,
            'cell_id' => $cell->id,
        ]);

        BiometricEnrollment::create([
            'branch_id' => $this->branch->id,
            'device_id' => $this->device->id,
            'member_id' => $member->id,
            'device_user_id' => $deviceUserId,
            'verification_mode' => 'fingerprint',
            'is_active' => true,
        ]);

        return [$member, $cell];
    }

    protected function scan(array $overrides = [])
    {
        return $this->withHeader('X-Biometric-Secret', $this->secret)->postJson('/api/biometric/scans', array_merge([
            'device_serial' => 'DEV-001',
            'device_user_id' => '1001',
            'verification_mode' => 'fingerprint',
        ], $overrides));
    }

    protected function adminToken(): string
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Secretary',
            'email' => 'secretary@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('secretary');

        return $user->createToken('test')->plainTextToken;
    }

    public function test_scan_resolves_member_and_records_snapshots(): void
    {
        [$member, $cell] = $this->makeMemberWithCell('Bethel', '1001');

        $this->scan()
            ->assertStatus(202)
            ->assertJsonPath('status', 'recorded')
            ->assertJsonPath('member.id', $member->id)
            ->assertJsonPath('member.cell_id', $cell->id);

        $this->assertDatabaseHas('attendance_sessions', [
            'branch_id' => $this->branch->id,
            'source' => AttendanceSession::SOURCE_BIOMETRIC,
            'count_toward_total' => true,
        ]);

        $this->assertDatabaseHas('attendance_records', [
            'member_id' => $member->id,
            'source' => AttendanceRecord::SOURCE_BIOMETRIC,
            'biometric_device_id' => $this->device->id,
            'cell_id' => $cell->id,
            'is_present' => true,
        ]);
    }

    public function test_duplicate_scan_within_window_is_suppressed(): void
    {
        [$member] = $this->makeMemberWithCell('Bethel', '1001');

        $this->scan()->assertStatus(202);
        $this->scan()->assertStatus(200)->assertJsonPath('status', 'duplicate');

        $this->assertSame(
            1,
            AttendanceRecord::withoutGlobalScopes()->where('member_id', $member->id)->count(),
        );
    }

    public function test_worker_is_idempotent_when_job_redelivered(): void
    {
        [$member, $cell] = $this->makeMemberWithCell('Bethel', '1001');

        $args = [
            $member->id,
            $this->branch->id,
            $cell->id,
            null,
            $this->device->id,
            'fingerprint',
            null,
            now()->toDateString(),
            now()->toDateTimeString(),
        ];

        RecordBiometricAttendanceJob::dispatchSync(...$args);
        RecordBiometricAttendanceJob::dispatchSync(...$args);

        $this->assertSame(
            1,
            AttendanceRecord::withoutGlobalScopes()->where('member_id', $member->id)->count(),
        );
    }

    public function test_unknown_fingerprint_is_not_recorded(): void
    {
        $this->scan(['device_user_id' => 'nothing-here'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'unknown');

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_scan_without_valid_secret_is_forbidden(): void
    {
        [$member] = $this->makeMemberWithCell('Bethel', '1001');

        $this->postJson('/api/biometric/scans', [
            'device_serial' => 'DEV-001',
            'device_user_id' => '1001',
        ])->assertStatus(403);

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_church_total_is_aggregated_across_cells(): void
    {
        [$memberA, $cellA] = $this->makeMemberWithCell('Bethel', '1001');
        [$memberB, $cellB] = $this->makeMemberWithCell('Zoe', '1002');

        $this->scan(['device_user_id' => '1001'])->assertStatus(202);
        $this->scan(['device_user_id' => '1002'])->assertStatus(202);

        $this->artisan('biometric:refresh-view', ['--force' => true])->assertSuccessful();

        $service = app(BiometricAttendanceService::class);
        $date = now()->toDateString();

        // Per-cell totals...
        $cellTotals = $service->cellTotalsForDate($this->branch->id, $date);
        $this->assertSame(2, $cellTotals->count());
        $this->assertSame(1, $cellTotals->firstWhere('cell_id', $cellA->id)->total_count);
        $this->assertSame(1, $cellTotals->firstWhere('cell_id', $cellB->id)->total_count);

        // ...summed into the church-wide total.
        $this->assertSame(2, (int) $service->dailyTotals($this->branch->id)->first()->total_count);

        $token = $this->adminToken();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/attendance/sundays')
            ->assertOk()
            ->assertJsonPath('data.0.total_count', '2')
            ->assertJsonPath('data.0.adult_count', '2');
    }

    public function test_biometric_date_is_authoritative_over_manual_session(): void
    {
        [$member, $cell] = $this->makeMemberWithCell('Bethel', '1001');

        // A fixed Sunday so the manual cell meeting is inside the Sunday scope.
        $sunday = '2026-08-02';

        // A cell leader also manually marks the member present in a cell
        // meeting on the same day — the biometric total must win.
        $cellServiceTypeId = ServiceType::firstOrCreate(
            ['slug' => 'cell_meeting'],
            ['name' => 'Cell Meeting', 'type' => 'combined', 'is_active' => true],
        )->id;

        $manual = AttendanceSession::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $cellServiceTypeId,
            'cell_id' => $cell->id,
            'service_date' => $sunday,
            'attendance_mode' => 'register',
        ]);
        AttendanceRecord::create([
            'session_id' => $manual->id,
            'member_id' => $member->id,
            'is_present' => true,
            'source' => AttendanceRecord::SOURCE_MANUAL,
        ]);

        $this->scan(['scanned_at' => $sunday.' 08:30:00'])->assertStatus(202);
        $this->artisan('biometric:refresh-view', ['--force' => true])->assertSuccessful();

        $token = $this->adminToken();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/attendance/sundays')
            ->assertOk()
            // One row for the date, total = 1 (not the double-counted 2).
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.service_date', $sunday)
            ->assertJsonPath('data.0.total_count', '1');
    }
}
