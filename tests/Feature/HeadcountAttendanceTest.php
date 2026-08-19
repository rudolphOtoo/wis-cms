<?php

namespace Tests\Feature;

use App\Models\AttendanceSession;
use App\Models\Branch;
use App\Models\Cell;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Headcount attendance mode (Phase 4). An usher records a church-wide
 * Men / Women / Children tally instead of a per-person roster; both modes
 * must feed the SAME aggregate stats/report shape via the
 * attendance_session_counts view.
 */
class HeadcountAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
    }

    protected function serviceType(): ServiceType
    {
        return ServiceType::firstOrCreate(
            ['slug' => 'sunday_adult'],
            [
                'branch_id' => $this->branch->id,
                'name' => 'Sunday Adult Service',
                'type' => 'adult',
                'is_active' => true,
            ]
        );
    }

    protected function usherToken(): string
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Usher',
            'email' => 'usher@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('usher'); // has 'view attendance' + 'create attendance'

        return $user->createToken('test')->plainTextToken;
    }

    /**
     * Admins pass request authorization, so the MODE guards (which live in
     * withValidator) are what reject cross-mode marks with a clean 422.
     */
    protected function secretaryToken(): string
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Secretary',
            'email' => 'secretary@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('secretary'); // has 'create attendance' + 'view attendance'

        return $user->createToken('test')->plainTextToken;
    }

    public function test_create_headcount_session_without_cell_and_save_door_tally(): void
    {
        $token = $this->usherToken();

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/attendance/sessions', [
                'service_type_id' => $this->serviceType()->id,
                'service_date' => '2026-08-02',
                'attendance_mode' => 'headcount',
            ])
            ->assertCreated();

        $sessionId = $create->json('data.id');
        $this->assertSame('headcount', $create->json('data.attendance_mode'));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/attendance/sessions/{$sessionId}/headcount", [
                'male_count' => 120,
                'female_count' => 150,
                'children_count' => 60,
            ])
            ->assertOk()
            // male/female are headcount-only columns in the resource
            ->assertJsonPath('data.male_count', 120)
            ->assertJsonPath('data.female_count', 150)
            // accessors backed by the counts view
            ->assertJsonPath('data.adult_count', 270)
            ->assertJsonPath('data.children_count', 60)
            ->assertJsonPath('data.total_count', 330);

        // showSession: headcount sessions expose no roster
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/attendance/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.people', [])
            ->assertJsonPath('data.session.total_count', 330);
    }

    public function test_headcount_session_rejects_cell_or_department_scope(): void
    {
        $token = $this->usherToken();

        $cell = Cell::create([
            'branch_id' => $this->branch->id,
            'name' => 'Bethel',
            'is_active' => true,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/attendance/sessions', [
                'service_type_id' => $this->serviceType()->id,
                'service_date' => '2026-08-02',
                'attendance_mode' => 'headcount',
                'cell_id' => $cell->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cell_id');
    }

    public function test_register_mark_on_headcount_session_is_rejected(): void
    {
        $token = $this->secretaryToken();

        $session = AttendanceSession::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $this->serviceType()->id,
            'service_date' => '2026-08-02',
            'attendance_mode' => 'headcount',
            'recorded_by' => User::first()->id,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/attendance/sessions/{$session->id}/mark", [
                'records' => [['type' => 'member', 'person_id' => 'not-a-uuid', 'is_present' => true]],
            ])
            ->assertStatus(422);
    }

    public function test_headcount_mark_on_register_session_is_rejected(): void
    {
        $token = $this->secretaryToken();

        $cell = Cell::create([
            'branch_id' => $this->branch->id,
            'name' => 'Bethel',
            'is_active' => true,
        ]);

        $session = AttendanceSession::create([
            'branch_id' => $this->branch->id,
            'service_type_id' => $this->serviceType()->id,
            'cell_id' => $cell->id,
            'service_date' => '2026-08-02',
            'attendance_mode' => 'register',
            'recorded_by' => User::first()->id,
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/attendance/sessions/{$session->id}/headcount", [
                'male_count' => 10,
                'female_count' => 10,
                'children_count' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('attendance_mode');
    }

    public function test_headcount_sessions_feed_the_same_stats_shape(): void
    {
        $token = $this->secretaryToken();

        $create = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/attendance/sessions', [
                'service_type_id' => $this->serviceType()->id,
                'service_date' => '2026-08-02',
                'attendance_mode' => 'headcount',
            ])
            ->assertCreated();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/attendance/sessions/{$create->json('data.id')}/headcount", [
                'male_count' => 120,
                'female_count' => 150,
                'children_count' => 60,
            ])
            ->assertOk();

        // The stats endpoint (shared by register AND headcount) returns the
        // same aggregate shape — headcount sessions are not excluded.
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
                    'insights',
                ],
            ])
            ->assertJsonPath('data.total_sessions', 1);

        // /attendance/sundays aggregates both modes through the view.
        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/attendance/sundays')
            ->assertOk();

        $res->assertJsonPath('data.0.adult_count', '270')
            ->assertJsonPath('data.0.children_count', '60')
            ->assertJsonPath('data.0.total_count', '330');
    }
}
