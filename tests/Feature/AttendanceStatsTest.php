<?php

namespace Tests\Feature;

use App\Models\Branch;
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
}
