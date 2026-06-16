<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cell;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CellTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
        $this->admin = User::create([
            'branch_id' => $this->branch->id, 'name' => 'Admin',
            'email' => 'admin@test.local', 'password' => Hash::make('x'), 'is_active' => true,
        ]);
        $this->admin->assignRole('super_admin');
    }

    protected function token(User $u): string
    {
        return $u->createToken('t')->plainTextToken;
    }

    protected function member(array $attrs = []): Member
    {
        return Member::create(array_merge([
            'branch_id' => $this->branch->id, 'first_name' => 'Kofi', 'last_name' => 'M',
            'gender' => 'male', 'status' => 'active',
        ], $attrs));
    }

    protected function asAdmin()
    {
        return $this->withHeader('Authorization', "Bearer {$this->token($this->admin)}");
    }

    public function test_admin_can_create_a_cell(): void
    {
        $this->asAdmin()->postJson('/api/cells', [
            'name' => 'Dansoman Cell', 'description' => 'Geographic',
        ])->assertCreated();

        $this->assertDatabaseHas('cells', ['name' => 'Dansoman Cell', 'branch_id' => $this->branch->id]);
    }

    public function test_assigning_a_member_to_a_cell_sets_their_cell_id(): void
    {
        $cell = Cell::create(['branch_id' => $this->branch->id, 'name' => 'Cell A', 'is_active' => true]);
        $m = $this->member();

        $this->asAdmin()->postJson("/api/cells/{$cell->id}/members/{$m->id}")->assertOk();

        $this->assertSame($cell->id, $m->fresh()->cell_id);
    }

    public function test_a_member_can_only_be_in_one_cell_assigning_to_a_second_moves_them(): void
    {
        $a = Cell::create(['branch_id' => $this->branch->id, 'name' => 'Cell A', 'is_active' => true]);
        $b = Cell::create(['branch_id' => $this->branch->id, 'name' => 'Cell B', 'is_active' => true]);
        $m = $this->member();

        $this->asAdmin()->postJson("/api/cells/{$a->id}/members/{$m->id}")->assertOk();
        $this->assertSame($a->id, $m->fresh()->cell_id);

        // Assign to B — should MOVE (one cell only)
        $this->asAdmin()->postJson("/api/cells/{$b->id}/members/{$m->id}")->assertOk();
        $this->assertSame($b->id, $m->fresh()->cell_id);

        // A now has 0 members, B has 1
        $this->assertSame(0, $a->fresh()->members()->count());
        $this->assertSame(1, $b->fresh()->members()->count());
    }

    public function test_unassigning_clears_the_cell(): void
    {
        $cell = Cell::create(['branch_id' => $this->branch->id, 'name' => 'Cell A', 'is_active' => true]);
        $m = $this->member(['cell_id' => $cell->id]);

        $this->asAdmin()->deleteJson("/api/cells/{$cell->id}/members/{$m->id}")->assertOk();

        $this->assertNull($m->fresh()->cell_id);
    }

    public function test_deleting_a_cell_nulls_member_cell_id(): void
    {
        $cell = Cell::create(['branch_id' => $this->branch->id, 'name' => 'Cell A', 'is_active' => true]);
        $m = $this->member(['cell_id' => $cell->id]);

        $this->asAdmin()->deleteJson("/api/cells/{$cell->id}")->assertOk();

        $this->assertNull($m->fresh()->cell_id);
    }

    public function test_member_role_cannot_manage_cells(): void
    {
        $member = User::create([
            'branch_id' => $this->branch->id, 'name' => 'Reg',
            'email' => 'reg@test.local', 'password' => Hash::make('x'), 'is_active' => true,
        ]);
        $member->assignRole('member');

        $this->withHeader('Authorization', "Bearer {$this->token($member)}")
            ->postJson('/api/cells', ['name' => 'Nope'])
            ->assertStatus(403);
    }
}
