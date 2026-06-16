<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LeaderDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
    }

    protected function userWithRole(string $role): User
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => ucfirst($role),
            'email' => "{$role}@wis-cms.local",
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_department_leader_dashboard_is_scoped_and_has_no_finance(): void
    {
        $leader = $this->userWithRole('department_leader');

        $dept = Department::create([
            'branch_id' => $this->branch->id,
            'name' => 'Choir',
            'leader_user_id' => $leader->id,
            'is_active' => true,
        ]);

        $member = Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'gender' => 'female',
        ]);
        $dept->members()->attach($member->id, ['role' => 'president', 'joined_at' => now()->toDateString()]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($leader)}")
            ->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.mode', 'department_leader')
            ->assertJsonPath('data.totals.total_active_members', 1)
            ->assertJsonPath('data.departments.0.name', 'Choir')
            ->assertJsonPath('data.departments.0.members.0.role', 'president');

        // The privacy guarantee: NO finance / church-wide keys anywhere.
        $json = $response->json('data');
        $this->assertArrayNotHasKey('finance_chart', $json);
        $this->assertArrayNotHasKey('top_categories', $json);
        $this->assertArrayNotHasKey('recent_transactions', $json);
        $this->assertArrayNotHasKey('hero', $json);
        $this->assertStringNotContainsString('income', strtolower($response->getContent()));
    }

    public function test_admin_dashboard_still_has_full_data(): void
    {
        $admin = $this->userWithRole('super_admin');

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($admin)}")
            ->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonStructure(['data' => ['hero', 'finance_chart', 'recent_transactions']]);
    }

    public function test_leader_with_no_department_gets_empty_scoped_dashboard(): void
    {
        $leader = $this->userWithRole('department_leader');

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($leader)}")
            ->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.mode', 'department_leader')
            ->assertJsonPath('data.totals.departments_led', 0);
    }
}
