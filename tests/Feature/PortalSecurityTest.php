<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PortalSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
    }

    protected function portalMember(): array
    {
        $member = Member::factory()->create(['branch_id' => $this->branch->id]);
        $user = User::create([
            'branch_id' => $this->branch->id,
            'member_id' => $member->id,
            'name' => $member->full_name,
            'email' => 'portal@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('member');

        return [$user, $member, $user->createToken('test')->plainTextToken];
    }

    public function test_member_can_view_own_profile(): void
    {
        [$user, $member, $token] = $this->portalMember();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/portal/profile')
            ->assertOk()
            ->assertJsonPath('data.member_number', $member->member_number);
    }

    public function test_member_can_view_own_giving(): void
    {
        [$user, $member, $token] = $this->portalMember();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/portal/giving')
            ->assertOk()
            ->assertJsonStructure(['data' => ['total', 'by_category', 'transactions']]);
    }

    // BUG-005 regression: Postgres numeric `amount` is cast to decimal:2
    // (a string), so round() previously threw a TypeError → 500.
    public function test_member_giving_returns_rounded_amounts_with_data(): void
    {
        [$user, $member, $token] = $this->portalMember();

        $category = FinanceCategory::factory()->create();
        Transaction::factory()->create([
            'branch_id' => $this->branch->id,
            'category_id' => $category->id,
            'member_id' => $member->id,
            'type' => 'income',
            'amount' => 1234.567,
            'transaction_date' => now(),
        ]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/portal/giving')
            ->assertOk()
            ->assertJsonPath('data.transactions.0.amount', 1234.57);
    }

    public function test_member_canno_t_access_staff_member_list(): void
    {
        [$user, $member, $token] = $this->portalMember();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/members')
            ->assertStatus(403);
    }

    public function test_member_canno_t_access_another_members_giving(): void
    {
        [$user, $member, $token] = $this->portalMember();
        $other = Member::factory()->create(['branch_id' => $this->branch->id]);

        // Staff endpoint requires 'view finance' — member has only 'access portal'
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/members/{$other->id}/giving")
            ->assertStatus(403);
    }

    public function test_member_canno_t_access_finance(): void
    {
        [$user, $member, $token] = $this->portalMember();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/finance/stats')
            ->assertStatus(403);
    }

    public function test_staff_user_without_member_link_gets_403_on_portal(): void
    {
        // A staff user with the member role but no member_id should be blocked cleanly
        $user = User::create([
            'branch_id' => $this->branch->id,
            'member_id' => null,
            'name' => 'No Profile',
            'email' => 'noprofile@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('member');
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/portal/profile')
            ->assertStatus(403);
    }
}
