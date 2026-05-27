<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberExportTest extends TestCase
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

    protected function token(User $u): string
    {
        return $u->createToken('test')->plainTextToken;
    }

    public function test_authorised_user_can_export_members_as_csv(): void
    {
        Member::create(['branch_id' => $this->branch->id, 'first_name' => 'Ama', 'last_name' => 'Mensah', 'gender' => 'female', 'phone' => '0241234567']);
        Member::create(['branch_id' => $this->branch->id, 'first_name' => 'Kofi', 'last_name' => 'Boateng', 'gender' => 'male', 'phone' => '0209876543']);

        $admin = $this->userWithRole('super_admin');

        $response = $this->withHeader('Authorization', "Bearer {$this->token($admin)}")
            ->get('/api/members/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Member Number', $csv); // header row
        $this->assertStringContainsString('Ama', $csv);
        $this->assertStringContainsString('Kofi', $csv);
    }

    public function test_export_respects_status_filter(): void
    {
        Member::create(['branch_id' => $this->branch->id, 'first_name' => 'Active', 'last_name' => 'One', 'gender' => 'male', 'status' => 'active']);
        Member::create(['branch_id' => $this->branch->id, 'first_name' => 'Inactive', 'last_name' => 'Two', 'gender' => 'male', 'status' => 'inactive']);

        $admin = $this->userWithRole('super_admin');

        $csv = $this->withHeader('Authorization', "Bearer {$this->token($admin)}")
            ->get('/api/members/export?status=active')
            ->streamedContent();

        $this->assertStringContainsString('Active', $csv);
        $this->assertStringNotContainsString('Inactive', $csv);
    }

    public function test_usher_without_export_permission_is_forbidden(): void
    {
        $usher = $this->userWithRole('usher');

        $this->withHeader('Authorization', "Bearer {$this->token($usher)}")
            ->get('/api/members/export')
            ->assertStatus(403);
    }
}
