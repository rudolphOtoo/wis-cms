<?php
namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use App\Models\Visitor;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VisitorConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function adminToken(Branch $branch): string
    {
        $user = User::create([
            'branch_id' => $branch->id,
            'name'      => 'Admin',
            'email'     => 'admin@test.local',
            'password'  => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('super_admin');
        return $user->createToken('test')->plainTextToken;
    }

    public function test_visitor_can_be_converted_to_member(): void
    {
        $branch  = Branch::factory()->create();
        $token   = $this->adminToken($branch);
        $visitor = Visitor::factory()->create([
            'branch_id'  => $branch->id,
            'first_name' => 'Yaw',
            'last_name'  => 'Boateng',
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/visitors/{$visitor->id}/convert", [
                'gender' => 'male',
            ]);

        $response->assertStatus(201);

        // A member now exists with the visitor's name
        $this->assertDatabaseHas('members', [
            'first_name' => 'Yaw',
            'last_name'  => 'Boateng',
        ]);

        // Visitor is linked and marked joined
        $visitor->refresh();
        $this->assertNotNull($visitor->converted_member_id);
        $this->assertEquals('joined', $visitor->follow_up_status);
    }

    public function test_visitor_cannot_be_converted_twice(): void
    {
        $branch  = Branch::factory()->create();
        $token   = $this->adminToken($branch);
        $member  = Member::factory()->create(['branch_id' => $branch->id]);
        $visitor = Visitor::factory()->create([
            'branch_id'           => $branch->id,
            'converted_member_id' => $member->id,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/visitors/{$visitor->id}/convert", [
                'gender' => 'male',
            ]);

        $response->assertStatus(422);
    }
}
