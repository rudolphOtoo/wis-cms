<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PermissionTest extends TestCase
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

    public function test_super_admin_can_view_members(): void
    {
        $token = $this->tokenFor($this->userWithRole('super_admin'));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/members/stats')
            ->assertOk();
    }

    public function test_usher_cannot_delete_members(): void
    {
        $member = Member::factory()->create(['branch_id' => $this->branch->id]);
        $token = $this->tokenFor($this->userWithRole('usher'));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson("/api/members/{$member->id}")
            ->assertStatus(403);
    }

    public function test_usher_cannot_access_finance(): void
    {
        $token = $this->tokenFor($this->userWithRole('usher'));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/finance/stats')
            ->assertStatus(403);
    }

    public function test_finance_officer_can_view_finance(): void
    {
        $token = $this->tokenFor($this->userWithRole('finance_officer'));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/finance/stats')
            ->assertOk();
    }

    public function test_secretary_can_create_members(): void
    {
        $token = $this->tokenFor($this->userWithRole('secretary'));

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/members', [
                'first_name' => 'New',
                'last_name' => 'Member',
                'gender' => 'male',
            ])
            ->assertStatus(201);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/members')
            ->assertStatus(401);
    }
}
