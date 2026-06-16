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

class DepartmentScopingTest extends TestCase
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

    protected function makeDepartment(string $name, ?User $leader = null): Department
    {
        return Department::create([
            'branch_id' => $this->branch->id,
            'name' => $name,
            'leader_user_id' => $leader?->id,
            'is_active' => true,
        ]);
    }

    public function test_department_leader_sees_only_their_led_department(): void
    {
        $leader = $this->userWithRole('department_leader');
        $own = $this->makeDepartment('Choir', $leader);
        $this->makeDepartment('Ushers'); // led by no one

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($leader)}")
            ->getJson('/api/departments');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($own->id, $data[0]['id']);
    }

    public function test_department_leader_gets_404_on_department_they_dont_lead(): void
    {
        $leader = $this->userWithRole('department_leader');
        $this->makeDepartment('Choir', $leader);
        $other = $this->makeDepartment('Ushers'); // not theirs

        $this->withHeader('Authorization', "Bearer {$this->tokenFor($leader)}")
            ->getJson("/api/departments/{$other->id}")
            ->assertStatus(404);
    }

    public function test_department_leader_can_see_members_of_own_department(): void
    {
        $leader = $this->userWithRole('department_leader');
        $own = $this->makeDepartment('Choir', $leader);

        $member = Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'gender' => 'female',
        ]);
        $own->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()->toDateString()]);

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($leader)}")
            ->getJson("/api/departments/{$own->id}/members");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_super_admin_sees_all_departments(): void
    {
        $this->makeDepartment('Choir');
        $this->makeDepartment('Ushers');
        $admin = $this->userWithRole('super_admin');

        $response = $this->withHeader('Authorization', "Bearer {$this->tokenFor($admin)}")
            ->getJson('/api/departments');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }
}
