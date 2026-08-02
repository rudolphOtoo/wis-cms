<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_number_is_auto_generated(): void
    {
        $branch = Branch::factory()->create();
        $member = Member::create([
            'branch_id' => $branch->id,
            'first_name' => 'Kofi',
            'last_name' => 'Mensah',
            'gender' => 'male',
        ]);

        $year = now()->format('Y');
        $this->assertEquals("WIS-{$year}-0001", $member->member_number);
    }

    public function test_member_numbers_increment_sequentially(): void
    {
        $branch = Branch::factory()->create();

        $first = Member::create([
            'branch_id' => $branch->id, 'first_name' => 'A', 'last_name' => 'One', 'gender' => 'male',
        ]);
        $second = Member::create([
            'branch_id' => $branch->id, 'first_name' => 'B', 'last_name' => 'Two', 'gender' => 'female',
        ]);

        $year = now()->format('Y');
        $this->assertEquals("WIS-{$year}-0001", $first->member_number);
        $this->assertEquals("WIS-{$year}-0002", $second->member_number);
    }

    public function test_member_full_name_accessor(): void
    {
        $member = Member::factory()->make([
            'first_name' => 'Ama',
            'other_names' => 'Serwaa',
            'last_name' => 'Owusu',
        ]);

        $this->assertEquals('Ama Serwaa Owusu', $member->full_name);
    }

    public function test_member_number_is_not_overwritten_if_provided(): void
    {
        $branch = Branch::factory()->create();
        $member = Member::create([
            'branch_id' => $branch->id,
            'member_number' => 'CUSTOM-001',
            'first_name' => 'Custom',
            'last_name' => 'Number',
            'gender' => 'male',
        ]);

        $this->assertEquals('CUSTOM-001', $member->member_number);
    }

    protected function seededAdmin(): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $admin = User::create([
            'branch_id' => $branch->id, 'name' => 'Admin',
            'email' => 'admin@test.local', 'password' => Hash::make('x'), 'is_active' => true,
        ]);
        $admin->assignRole('super_admin');

        return [$branch, $admin];
    }

    protected function memberPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ama', 'last_name' => 'Serwaa', 'gender' => 'female', 'phone' => '0241111111',
        ], $overrides);
    }

    // BUG-004 regression: a duplicate (branch_id, phone) must return 422,
    // not a 500 from the DB unique constraint.
    public function test_duplicate_phone_is_rejected_with_422_on_create(): void
    {
        [$branch, $admin] = $this->seededAdmin();
        $admin->refresh();

        Member::create([
            'branch_id' => $branch->id, 'first_name' => 'Existing',
            'last_name' => 'Member', 'gender' => 'male', 'phone' => '0241111111',
        ]);

        $this->withHeader('Authorization', "Bearer {$admin->createToken('t')->plainTextToken}")
            ->postJson('/api/members', $this->memberPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_duplicate_phone_is_rejected_with_422_on_update(): void
    {
        [$branch, $admin] = $this->seededAdmin();

        $first = Member::create([
            'branch_id' => $branch->id, 'first_name' => 'First',
            'last_name' => 'Member', 'gender' => 'male', 'phone' => '0241111111',
        ]);
        $second = Member::create([
            'branch_id' => $branch->id, 'first_name' => 'Second',
            'last_name' => 'Member', 'gender' => 'female', 'phone' => '0242222222',
        ]);

        $this->withHeader('Authorization', "Bearer {$admin->createToken('t')->plainTextToken}")
            ->putJson("/api/members/{$second->id}", ['phone' => '0241111111'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_update_keeping_own_phone_is_allowed(): void
    {
        [$branch, $admin] = $this->seededAdmin();

        $member = Member::create([
            'branch_id' => $branch->id, 'first_name' => 'Own',
            'last_name' => 'Phone', 'gender' => 'male', 'phone' => '0241111111',
        ]);

        $this->withHeader('Authorization', "Bearer {$admin->createToken('t')->plainTextToken}")
            ->putJson("/api/members/{$member->id}", [
                'phone' => '0241111111', 'first_name' => 'Renamed', 'last_name' => 'Phone',
            ])
            ->assertOk();
    }

    public function test_same_phone_in_another_branch_is_allowed(): void
    {
        [$branch, $admin] = $this->seededAdmin();
        $other = Branch::factory()->create();

        Member::create([
            'branch_id' => $other->id, 'member_number' => 'OTHER-0001',
            'first_name' => 'Other', 'last_name' => 'Branch', 'gender' => 'male', 'phone' => '0241111111',
        ]);

        $this->withHeader('Authorization', "Bearer {$admin->createToken('t')->plainTextToken}")
            ->postJson('/api/members', $this->memberPayload())
            ->assertStatus(201);
    }
}
