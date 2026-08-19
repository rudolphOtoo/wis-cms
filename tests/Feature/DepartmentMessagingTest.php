<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Member;
use App\Models\MessageRecipient;
use App\Models\User;
use App\Services\MnotifySmsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class DepartmentMessagingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
        Mail::fake();
    }

    protected function userWithRole(string $role): User
    {
        $user = User::create([
            'branch_id' => $this->branch->id, 'name' => ucfirst($role),
            'email' => "{$role}@wis-cms.local", 'password' => Hash::make('Password@123'), 'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    protected function token(User $u): string
    {
        return $u->createToken('test')->plainTextToken;
    }

    protected function dept(string $name, ?User $leader = null): Department
    {
        return Department::create([
            'branch_id' => $this->branch->id, 'name' => $name, 'is_active' => true,
            'leader_user_id' => $leader?->id,
        ]);
    }

    protected function addMember(Department $d, array $attrs = []): Member
    {
        $m = Member::create(array_merge([
            'branch_id' => $this->branch->id, 'first_name' => 'Mem', 'last_name' => 'Ber',
            'gender' => 'male', 'status' => 'active', 'phone' => '0241234567',
        ], $attrs));
        $d->members()->attach($m->id, ['role' => 'member', 'joined_at' => now()->toDateString()]);

        return $m;
    }

    public function test_leader_can_message_their_own_department(): void
    {
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->andReturn(true));

        $leader = $this->userWithRole('department_leader');
        $choir = $this->dept('Choir', $leader);
        $this->addMember($choir, ['phone' => '0240000001']);
        $this->addMember($choir, ['phone' => '0240000002']);

        $response = $this->withHeader('Authorization', "Bearer {$this->token($leader)}")
            ->postJson("/api/departments/{$choir->id}/message", [
                'body' => 'Choir rehearsal moved to 5pm.', 'channel' => 'sms',
            ]);

        $response->assertCreated();
        $this->assertSame(2, MessageRecipient::count());
    }

    public function test_leader_cannot_message_a_department_they_dont_lead(): void
    {
        $this->mock(MnotifySmsService::class, fn ($m) => $m->shouldReceive('send')->never());

        $leader = $this->userWithRole('department_leader');
        $this->dept('Choir', $leader);            // leads this
        $youth = $this->dept('Youth');            // does NOT lead this
        $this->addMember($youth, ['phone' => '0249999999']);

        $this->withHeader('Authorization', "Bearer {$this->token($leader)}")
            ->postJson("/api/departments/{$youth->id}/message", [
                'body' => 'Should not reach', 'channel' => 'sms',
            ])
            ->assertStatus(404); // scopedQuery hides it → not found

        $this->assertSame(0, MessageRecipient::count());
    }

    public function test_usher_cannot_message_a_department(): void
    {
        $usher = $this->userWithRole('usher');
        $choir = $this->dept('Choir');
        $this->addMember($choir);

        $this->withHeader('Authorization', "Bearer {$this->token($usher)}")
            ->postJson("/api/departments/{$choir->id}/message", [
                'body' => 'No', 'channel' => 'sms',
            ])
            ->assertStatus(403); // lacks 'message own department'
    }

    public function test_message_requires_a_body(): void
    {
        $leader = $this->userWithRole('department_leader');
        $choir = $this->dept('Choir', $leader);
        $this->addMember($choir);

        $this->withHeader('Authorization', "Bearer {$this->token($leader)}")
            ->postJson("/api/departments/{$choir->id}/message", ['channel' => 'sms'])
            ->assertStatus(422);
    }

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } catch (\Throwable) {
        }
        parent::tearDown();
    }
}
