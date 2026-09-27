<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The child form picks a guardian through a live type-ahead search
 * (GET /api/members?search=...) rather than a preloaded <select>.
 *
 * Two things this locks down:
 *  1. A guardian beyond the first page of members is still findable. The
 *     old dropdown fetched 500 and stopped, so a bigger church simply
 *     could not choose anyone past that cut-off.
 *  2. The form sends `unscoped=1` so a guardian in ANY cell of the branch
 *     is offered. That must not weaken the tenant boundary — branch
 *     isolation is a global scope on the model, not a request parameter.
 */
class GuardianSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function adminFor(Branch $branch): User
    {
        $admin = User::create([
            'branch_id' => $branch->id, 'name' => 'Admin',
            'email' => "admin-{$branch->id}@test.local", 'password' => Hash::make('x'),
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');

        return $admin;
    }

    protected function member(Branch $branch, string $first, string $last, string $phone = '0240000000'): Member
    {
        return Member::create([
            'branch_id' => $branch->id,
            'first_name' => $first, 'last_name' => $last,
            'gender' => 'male', 'phone' => $phone, 'status' => 'active',
        ]);
    }

    public function test_guardian_can_be_found_by_name_beyond_the_first_page(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $admin = $this->adminFor($branch);

        // 40 members sort before the guardian alphabetically, so any
        // first-page-only implementation would miss them.
        for ($i = 0; $i < 40; $i++) {
            $this->member($branch, 'Aaron', sprintf('Padding%02d', $i), "0241000{$i}");
        }
        $guardian = $this->member($branch, 'Zebedee', 'Okoro', '0245555555');

        $response = $this->withHeader('Authorization', "Bearer {$admin->createToken('t')->plainTextToken}")
            ->getJson('/api/members?search=Zebedee&status=active&per_page=25&unscoped=1');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($guardian->id, $response->json('data.0.id'));
    }

    public function test_guardian_search_also_matches_phone_and_member_number(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $admin = $this->adminFor($branch);

        $guardian = $this->member($branch, 'Grace', 'Ansah', '0247778888');

        $token = "Bearer {$admin->createToken('t')->plainTextToken}";

        $this->withHeader('Authorization', $token)
            ->getJson('/api/members?search=0247778888&unscoped=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $guardian->id);

        $this->withHeader('Authorization', $token)
            ->getJson('/api/members?search='.$guardian->member_number.'&unscoped=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $guardian->id);
    }

    public function test_unscoped_guardian_search_never_crosses_branches(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $other = Branch::factory()->create();
        $admin = $this->adminFor($branch);

        $mine = $this->member($branch, 'Kofi', 'Mensah', '0241001001');
        $theirs = $this->member($other, 'Kofi', 'Mensah', '0241001002');

        $response = $this->withHeader('Authorization', "Bearer {$admin->createToken('t')->plainTextToken}")
            ->getJson('/api/members?search=Kofi&unscoped=1&per_page=50');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id), 'Guardian in the user\'s own branch should be offered.');
        $this->assertFalse($ids->contains($theirs->id), 'unscoped must not expose another branch\'s members.');
    }

    public function test_guardian_search_respects_the_active_status_filter(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $admin = $this->adminFor($branch);

        $inactive = $this->member($branch, 'Inactive', 'Guardian', '0249009001');
        $inactive->update(['status' => 'inactive']);

        $this->member($branch, 'Active', 'Guardian', '0249009002');

        $token = "Bearer {$admin->createToken('t')->plainTextToken}";

        $this->withHeader('Authorization', $token)
            ->getJson('/api/members?search=Guardian&status=active&unscoped=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        // Without the filter the inactive member is still visible, which is
        // what lets an existing child keep displaying a retired guardian.
        $this->withHeader('Authorization', $token)
            ->getJson('/api/members?search=Guardian&unscoped=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_guardian_search_escapes_like_wildcards(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $admin = $this->adminFor($branch);

        $this->member($branch, 'Percent', 'Literal', '0246006001');

        $this->withHeader('Authorization', "Bearer {$admin->createToken('t')->plainTextToken}")
            ->getJson('/api/members?search=%25&unscoped=1&per_page=50')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
