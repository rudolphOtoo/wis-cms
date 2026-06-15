<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\AttendanceSession;
use App\Models\Branch;
use App\Models\Cell;
use App\Models\Member;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceStatsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
    }

    protected function staffToken(): string
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Usher',
            'email' => 'usher@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('usher'); // usher has 'view attendance'

        return $user->createToken('test')->plainTextToken;
    }

    public function test_stats_returns_the_expected_shape(): void
    {
        $token = $this->staffToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/attendance/stats')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'last_sunday',
                    'average',
                    'total_sessions',
                    'chart',
                    'monthly_trend',
                    'week_over_week_pct',
                    'insights' => [
                        'top_service',
                        'avg_adults',
                        'avg_children',
                        'trend_direction',
                    ],
                ],
            ]);
    }

    public function test_stats_are_safe_with_no_sessions(): void
    {
        $token = $this->staffToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/attendance/stats')
            ->assertOk()
            ->assertJsonPath('data.total_sessions', 0)
            ->assertJsonPath('data.week_over_week_pct', null)
            ->assertJsonPath('data.insights.trend_direction', 'flat');
    }

    public function test_last_sunday_sums_cell_sessions_with_breakdown(): void
    {
        // Two cells, both record adult attendance for the same Sunday.
        // Architecture B: last_sunday.total = sum, by_cell shows breakdown.

        $serviceType = ServiceType::create([
            'branch_id' => $this->branch->id,
            'name' => 'Sunday Adult Service',
            'slug' => 'sunday_adult_test',
            'type' => 'adult',
            'is_active' => true,
        ]);

        $cellA = Cell::create([
            'branch_id' => $this->branch->id,
            'name' => 'Bethel',
            'is_active' => true,
        ]);
        $cellB = Cell::create([
            'branch_id' => $this->branch->id,
            'name' => 'Spintex',
            'is_active' => true,
        ]);

        $sunday = '2026-06-14';

        $sessionA = AttendanceSession::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $serviceType->id,
            'cell_id' => $cellA->id,
            'service_date' => $sunday,
        ]);
        $sessionB = AttendanceSession::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $serviceType->id,
            'cell_id' => $cellB->id,
            'service_date' => $sunday,
        ]);

        // Cell A: 3 adult members present
        // Cell B: 5 adult members present
        for ($i = 0; $i < 3; $i++) {
            $m = Member::create([
                'branch_id' => $this->branch->id,
                'first_name' => "A{$i}", 'last_name' => 'X',
                'gender' => 'male', 'phone' => '02400000'.str_pad($i, 2, '0', STR_PAD_LEFT),
                'status' => 'active',
            ]);
            AttendanceRecord::create([
                'session_id' => $sessionA->id,
                'member_id' => $m->id,
                'is_present' => true,
            ]);
        }
        for ($i = 0; $i < 5; $i++) {
            $m = Member::create([
                'branch_id' => $this->branch->id,
                'first_name' => "B{$i}", 'last_name' => 'Y',
                'gender' => 'female', 'phone' => '02500000'.str_pad($i, 2, '0', STR_PAD_LEFT),
                'status' => 'active',
            ]);
            AttendanceRecord::create([
                'session_id' => $sessionB->id,
                'member_id' => $m->id,
                'is_present' => true,
            ]);
        }

        $token = $this->staffToken();
        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/attendance/stats')
            ->assertOk();

        // Total is sum of both cells (3 + 5 = 8)
        $res->assertJsonPath('data.last_sunday.total', 8);
        // by_cell breakdown shows each cell's count
        $res->assertJsonPath('data.last_sunday.by_cell.Bethel', 3);
        $res->assertJsonPath('data.last_sunday.by_cell.Spintex', 5);
        // The date matches
        $res->assertJsonPath('data.last_sunday.date', $sunday);
    }

    public function test_create_adult_session_without_cell_id_is_rejected(): void
    {
        // Architecture B rule: adult service attendance MUST have cell_id.

        $serviceType = ServiceType::create([
            'branch_id' => $this->branch->id,
            'name' => 'Sunday Adult Service',
            'slug' => 'sunday_adult_test',
            'type' => 'adult',
            'is_active' => true,
        ]);

        // Create a user with 'create attendance' permission.
        // Pastor doesn't have it by default (ushers/cell_leaders do);
        // super_admin has all permissions via the wildcard grant.
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Admin',
            'email' => 'admin-create-attendance@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');
        $token = $user->createToken('test')->plainTextToken;

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/attendance/sessions', [
                'service_type_id' => $serviceType->id,
                'service_date' => '2026-06-14',
                // No cell_id — should be rejected for adult service
            ]);

        $res->assertStatus(422);
        $res->assertJsonPath('message', 'Adult service attendance must be recorded per cell. Please select a cell.');
    }
}
