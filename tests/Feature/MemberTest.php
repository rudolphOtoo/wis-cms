<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
