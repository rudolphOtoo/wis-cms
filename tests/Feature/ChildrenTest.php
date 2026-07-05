<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cell;
use App\Models\Children;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChildrenTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $secretary;

    protected User $superAdmin;

    protected Member $guardian;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create();

        $this->secretary = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Secretary',
            'email' => 'sec@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $this->secretary->assignRole('secretary');

        $this->superAdmin = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $this->superAdmin->assignRole('super_admin');

        $this->guardian = Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Ama',
            'last_name' => 'Mensah',
            'gender' => 'female',
        ]);
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function childPayload(array $overrides = []): array
    {
        return array_merge([
            'guardian_member_id' => $this->guardian->id,
            'first_name' => 'Kwame',
            'last_name' => 'Mensah',
            'gender' => 'male',
            'class_group' => 'Primary 1',
            'date_of_birth' => '2018-03-15',
        ], $overrides);
    }

    // ── List ───────────────────────────────────────────────────────────────

    public function test_secretary_can_list_children(): void
    {
        Children::create([...$this->childPayload(), 'branch_id' => $this->branch->id]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->getJson('/api/children')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page']]);
    }

    public function test_usher_can_view_children_list(): void
    {
        $usher = User::create([
            'branch_id' => $this->branch->id, 'name' => 'Usher',
            'email' => 'usher@test.local', 'password' => Hash::make('Password@123'), 'is_active' => true,
        ]);
        $usher->assignRole('usher');

        $this->withHeader('Authorization', "Bearer {$this->token($usher)}")
            ->getJson('/api/children')
            ->assertOk();
    }

    public function test_children_are_scoped_to_branch(): void
    {
        $otherBranch = Branch::factory()->create();
        $otherGuardian = Member::create([
            'branch_id' => $otherBranch->id, 'first_name' => 'Other', 'last_name' => 'Guardian', 'gender' => 'male',
        ]);
        Children::create([...$this->childPayload(), 'branch_id' => $otherBranch->id, 'guardian_member_id' => $otherGuardian->id]);

        $ownChild = Children::create([...$this->childPayload(), 'branch_id' => $this->branch->id, 'first_name' => 'Owned']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->getJson('/api/children')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($ownChild->id, $ids);
        $this->assertCount(1, $ids, 'Should only see own-branch children');
    }

    // ── Create ─────────────────────────────────────────────────────────────

    public function test_secretary_can_register_a_child(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->postJson('/api/children', $this->childPayload())
            ->assertCreated()
            ->assertJsonPath('data.first_name', 'Kwame')
            ->assertJsonPath('data.class_group', 'Primary 1');

        $this->assertDatabaseHas('children', [
            'first_name' => 'Kwame',
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_create_requires_guardian_member_id(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->postJson('/api/children', $this->childPayload(['guardian_member_id' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guardian_member_id']);
    }

    public function test_create_rejects_unknown_guardian(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->postJson('/api/children', $this->childPayload(['guardian_member_id' => '00000000-0000-0000-0000-000000000000']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['guardian_member_id']);
    }

    public function test_create_rejects_invalid_gender(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->postJson('/api/children', $this->childPayload(['gender' => 'other']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gender']);
    }

    public function test_usher_cannot_register_a_child(): void
    {
        $usher = User::create([
            'branch_id' => $this->branch->id, 'name' => 'Usher',
            'email' => 'usher@test.local', 'password' => Hash::make('Password@123'), 'is_active' => true,
        ]);
        $usher->assignRole('usher');

        $this->withHeader('Authorization', "Bearer {$this->token($usher)}")
            ->postJson('/api/children', $this->childPayload())
            ->assertForbidden();
    }

    // ── Show ───────────────────────────────────────────────────────────────

    public function test_show_returns_child_with_guardian(): void
    {
        $child = Children::create([...$this->childPayload(), 'branch_id' => $this->branch->id]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->getJson("/api/children/{$child->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $child->id)
            ->assertJsonStructure(['data' => ['id', 'first_name', 'last_name', 'gender', 'guardian']]);
    }

    // ── Update ─────────────────────────────────────────────────────────────

    public function test_secretary_can_update_a_child(): void
    {
        $child = Children::create([...$this->childPayload(), 'branch_id' => $this->branch->id]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->putJson("/api/children/{$child->id}", ['class_group' => 'Primary 2'])
            ->assertOk()
            ->assertJsonPath('data.class_group', 'Primary 2');
    }

    public function test_usher_cannot_update_a_child(): void
    {
        $child = Children::create([...$this->childPayload(), 'branch_id' => $this->branch->id]);

        $usher = User::create([
            'branch_id' => $this->branch->id, 'name' => 'Usher',
            'email' => 'usher@test.local', 'password' => Hash::make('Password@123'), 'is_active' => true,
        ]);
        $usher->assignRole('usher');

        $this->withHeader('Authorization', "Bearer {$this->token($usher)}")
            ->putJson("/api/children/{$child->id}", ['class_group' => 'Primary 2'])
            ->assertForbidden();
    }

    // ── Delete ─────────────────────────────────────────────────────────────

    public function test_super_admin_can_delete_a_child(): void
    {
        $child = Children::create([...$this->childPayload(), 'branch_id' => $this->branch->id]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->superAdmin)}")
            ->deleteJson("/api/children/{$child->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Child removed successfully.');

        $this->assertSoftDeleted('children', ['id' => $child->id]);
    }

    public function test_secretary_cannot_delete_a_child(): void
    {
        $child = Children::create([...$this->childPayload(), 'branch_id' => $this->branch->id]);

        $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->deleteJson("/api/children/{$child->id}")
            ->assertForbidden();
    }

    // ── Stats ──────────────────────────────────────────────────────────────

    // ── Filter: exclude_cell_id ──────────────────────────────────────────

    public function test_exclude_cell_id_filter_excludes_children_in_that_cell(): void
    {
        $cmCell = Cell::create([
            'branch_id' => $this->branch->id, 'name' => 'Children Ministry', 'is_active' => true,
        ]);

        $inCell = Children::create([...$this->childPayload(['first_name' => 'In']), 'branch_id' => $this->branch->id, 'cell_id' => $cmCell->id]);
        $unassigned = Children::create([...$this->childPayload(['first_name' => 'Free']), 'branch_id' => $this->branch->id, 'cell_id' => null]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->getJson('/api/children?exclude_cell_id='.$cmCell->id.'&per_page=50')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('first_name')->all();
        $this->assertContains('Free', $ids);
        $this->assertNotContains('In', $ids);
        $this->assertCount(1, $ids);
    }

    public function test_exclude_cell_id_still_returns_unassigned_children(): void
    {
        $cmCell = Cell::create([
            'branch_id' => $this->branch->id, 'name' => 'Children Ministry', 'is_active' => true,
        ]);

        Children::create([...$this->childPayload(['first_name' => 'ChildIn']), 'branch_id' => $this->branch->id, 'cell_id' => $cmCell->id]);
        Children::create([...$this->childPayload(['first_name' => 'ChildFree']), 'branch_id' => $this->branch->id, 'cell_id' => null]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->getJson('/api/children?exclude_cell_id='.$cmCell->id.'&per_page=50')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('first_name')->all();
        $this->assertContains('ChildFree', $ids);
        $this->assertNotContains('ChildIn', $ids);
        $this->assertCount(1, $ids);
    }

    public function test_exclude_cell_id_shows_children_in_other_cells(): void
    {
        $cmCell = Cell::create([
            'branch_id' => $this->branch->id, 'name' => 'Children Ministry', 'is_active' => true,
        ]);
        $otherCell = Cell::create([
            'branch_id' => $this->branch->id, 'name' => 'Other Cell', 'is_active' => true,
        ]);

        Children::create([...$this->childPayload(['first_name' => 'InCM']), 'branch_id' => $this->branch->id, 'cell_id' => $cmCell->id]);
        Children::create([...$this->childPayload(['first_name' => 'InOther']), 'branch_id' => $this->branch->id, 'cell_id' => $otherCell->id]);
        Children::create([...$this->childPayload(['first_name' => 'Unassigned']), 'branch_id' => $this->branch->id, 'cell_id' => null]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->getJson('/api/children?exclude_cell_id='.$cmCell->id.'&per_page=50')
            ->assertOk();

        $ids = collect($response->json('data'))->pluck('first_name')->all();
        $this->assertContains('InOther', $ids, 'Child in a different cell should be visible');
        $this->assertContains('Unassigned', $ids, 'Unassigned child should be visible');
        $this->assertNotContains('InCM', $ids, 'Child in the excluded cell should be hidden');
    }

    public function test_stats_returns_correct_counts(): void
    {
        Children::create([...$this->childPayload(['gender' => 'male',   'class_group' => 'Nursery']), 'branch_id' => $this->branch->id]);
        Children::create([...$this->childPayload(['gender' => 'female', 'class_group' => 'Primary 1', 'first_name' => 'Ama']), 'branch_id' => $this->branch->id]);
        Children::create([...$this->childPayload(['gender' => 'male',   'class_group' => 'Primary 1', 'first_name' => 'Kojo', 'is_active' => false]), 'branch_id' => $this->branch->id]);

        $data = $this->withHeader('Authorization', "Bearer {$this->token($this->secretary)}")
            ->getJson('/api/children/stats')
            ->assertOk()
            ->json('data');

        $this->assertEquals(3, $data['total']);
        $this->assertEquals(2, $data['active']);
        $this->assertEquals(2, $data['male']);
        $this->assertEquals(1, $data['female']);
        $this->assertArrayHasKey('Primary 1', $data['by_class']);
        $this->assertEquals(2, $data['by_class']['Primary 1']);
    }
}
