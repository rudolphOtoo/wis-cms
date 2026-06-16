<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Rules\AssignableRole;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AssignableRoleTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
    }

    private function makeUser(string $role): User
    {
        static $i = 0;
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => $role,
            'email' => $role.(++$i).'@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function passes(User $assigner, string $role): bool
    {
        $failed = false;
        (new AssignableRole($assigner))->validate('role', $role, function () use (&$failed) {
            $failed = true;
        });

        return ! $failed;
    }

    // ── super_admin ceiling ────────────────────────────────────────────────

    public function test_super_admin_can_assign_every_role(): void
    {
        $admin = $this->makeUser('super_admin');

        foreach (['super_admin', 'pastor', 'secretary', 'finance_officer', 'department_leader', 'cell_leader', 'usher', 'member'] as $role) {
            $this->assertTrue($this->passes($admin, $role), "super_admin should be able to assign '{$role}'");
        }
    }

    public function test_super_admin_cannot_assign_unknown_role(): void
    {
        $admin = $this->makeUser('super_admin');

        $this->assertFalse($this->passes($admin, 'ghost_role'));
    }

    // ── pastor ceiling ────────────────────────────────────────────────────

    public function test_pastor_can_assign_roles_below_their_tier(): void
    {
        $pastor = $this->makeUser('pastor');

        foreach (['secretary', 'finance_officer', 'department_leader', 'cell_leader', 'usher', 'member'] as $role) {
            $this->assertTrue($this->passes($pastor, $role), "pastor should be able to assign '{$role}'");
        }
    }

    public function test_pastor_cannot_assign_super_admin(): void
    {
        $this->assertFalse($this->passes($this->makeUser('pastor'), 'super_admin'));
    }

    public function test_pastor_cannot_assign_another_pastor(): void
    {
        $this->assertFalse($this->passes($this->makeUser('pastor'), 'pastor'));
    }

    // ── secretary / finance_officer ceiling ───────────────────────────────

    public function test_secretary_can_assign_roles_at_or_below_their_tier(): void
    {
        $secretary = $this->makeUser('secretary');

        foreach (['department_leader', 'cell_leader', 'usher', 'member'] as $role) {
            $this->assertTrue($this->passes($secretary, $role), "secretary should be able to assign '{$role}'");
        }
    }

    public function test_secretary_cannot_assign_pastor(): void
    {
        $this->assertFalse($this->passes($this->makeUser('secretary'), 'pastor'));
    }

    public function test_secretary_cannot_assign_another_secretary(): void
    {
        $this->assertFalse($this->passes($this->makeUser('secretary'), 'secretary'));
    }

    public function test_finance_officer_shares_secretary_ceiling(): void
    {
        $finance = $this->makeUser('finance_officer');

        $this->assertTrue($this->passes($finance, 'cell_leader'));
        $this->assertTrue($this->passes($finance, 'usher'));
        $this->assertFalse($this->passes($finance, 'pastor'));
        $this->assertFalse($this->passes($finance, 'finance_officer'));
    }

    // ── restricted roles (can assign nobody) ─────────────────────────────

    public function test_department_leader_cannot_assign_any_role(): void
    {
        $leader = $this->makeUser('department_leader');

        foreach (['member', 'cell_leader', 'usher', 'secretary', 'super_admin'] as $role) {
            $this->assertFalse($this->passes($leader, $role), "department_leader should NOT be able to assign '{$role}'");
        }
    }

    public function test_cell_leader_cannot_assign_any_role(): void
    {
        $leader = $this->makeUser('cell_leader');

        foreach (['member', 'usher', 'cell_leader', 'super_admin'] as $role) {
            $this->assertFalse($this->passes($leader, $role), "cell_leader should NOT be able to assign '{$role}'");
        }
    }

    public function test_usher_cannot_assign_any_role(): void
    {
        $usher = $this->makeUser('usher');

        $this->assertFalse($this->passes($usher, 'member'));
    }

    // ── API-level privilege-escalation prevention ─────────────────────────

    public function test_secretary_is_blocked_from_creating_a_pastor_via_api(): void
    {
        $secretary = $this->makeUser('secretary');

        $this->withHeader('Authorization', "Bearer {$secretary->createToken('test')->plainTextToken}")
            ->postJson('/api/users', [
                'name' => 'New Pastor',
                'email' => 'pastor@wis-cms.local',
                'role' => 'pastor',
            ])
            ->assertForbidden();
    }
}
