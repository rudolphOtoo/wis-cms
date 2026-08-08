<?php

namespace Tests\Feature;

use App\Diocese\Contracts\MemberNumberGenerator;
use App\Diocese\Strategies\McghMemberNumberGenerator;
use App\Diocese\Strategies\WisMemberNumberGenerator;
use App\Models\Branch;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Member-number generation is bound per profile (DioceseServiceProvider):
 * the default WIS scheme is WIS-{year}-{0001}; the MCC diocese uses
 * MCC/{year}/{00001}.
 */
class MemberNumberProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
    }

    private int $serial = 0;

    protected function createMember(): Member
    {
        $this->serial++;

        return Member::create([
            'branch_id' => $this->branch->id,
            'first_name' => 'Grace',
            'last_name' => 'Appiah',
            'gender' => 'female',
            'phone' => '024123456'.str_pad($this->serial, 2, '0', STR_PAD_LEFT),
            'status' => 'active',
        ]);
    }

    public function test_default_wis_profile_uses_wis_member_numbers(): void
    {
        $member = $this->createMember();

        $this->assertMatchesRegularExpression('/^WIS-\d{4}-\d{4}$/', $member->member_number);
    }

    public function test_mcgh_profile_uses_mcc_member_numbers(): void
    {
        // Simulate DIOCESE_PROFILE=mcgh. The generator singleton is bound
        // lazily (resolved on first Member creation), so swapping the
        // profile key before resolution picks the MCC strategy.
        $this->app->forgetInstance(MemberNumberGenerator::class);
        config(['diocese.key' => 'mcgh']);

        $first = $this->createMember();
        $second = $this->createMember();

        $year = now()->format('Y');
        $this->assertSame("MCC/{$year}/00001", $first->member_number);
        $this->assertSame("MCC/{$year}/00002", $second->member_number);
    }

    public function test_mcgh_generator_is_bound_for_mcgh_profile(): void
    {
        $this->app->forgetInstance(MemberNumberGenerator::class);
        config(['diocese.key' => 'mcgh']);

        $this->assertInstanceOf(McghMemberNumberGenerator::class, app(MemberNumberGenerator::class));
    }

    public function test_wis_generator_is_bound_for_default_profile(): void
    {
        $this->assertInstanceOf(WisMemberNumberGenerator::class, app(MemberNumberGenerator::class));
    }
}
