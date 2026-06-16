<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Member;
use App\Models\ServiceType;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DepartmentMeetingTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected ServiceType $deptMeetingType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();

        // The add_department_id migration already seeds this type;
        // fetch it rather than re-creating (slug is unique).
        $this->deptMeetingType = ServiceType::firstOrCreate(
            ['slug' => 'department-meeting'],
            ['name' => 'Department Meeting', 'type' => 'combined', 'is_active' => true]
        );
    }

    protected function leaderOf(?Department $dept = null): User
    {
        $user = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Leader',
            'email' => 'leader@wis-cms.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('department_leader');
        if ($dept) {
            $dept->update(['leader_user_id' => $user->id]);
        }

        return $user;
    }

    protected function dept(string $name): Department
    {
        return Department::create([
            'branch_id' => $this->branch->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    protected function token(User $u): string
    {
        return $u->createToken('test')->plainTextToken;
    }

    public function test_leader_can_open_a_meeting_for_their_department(): void
    {
        $choir = $this->dept('Choir');
        $leader = $this->leaderOf($choir);

        $this->withHeader('Authorization', "Bearer {$this->token($leader)}")
            ->postJson('/api/attendance/sessions', [
                'service_type_id' => $this->deptMeetingType->id,
                'department_id' => $choir->id,
                'service_date' => now()->toDateString(),
            ])
            ->assertCreated();
    }

    public function test_leader_cannot_open_a_meeting_for_another_department(): void
    {
        $choir = $this->dept('Choir');
        $youth = $this->dept('Youth');
        $leader = $this->leaderOf($choir); // leads Choir, NOT Youth

        $this->withHeader('Authorization', "Bearer {$this->token($leader)}")
            ->postJson('/api/attendance/sessions', [
                'service_type_id' => $this->deptMeetingType->id,
                'department_id' => $youth->id,
                'service_date' => now()->toDateString(),
            ])
            ->assertStatus(403);
    }

    public function test_recording_a_meeting_makes_dashboard_attendance_compute(): void
    {
        $choir = $this->dept('Choir');
        $leader = $this->leaderOf($choir);

        // 2 members in the choir
        $m1 = Member::create(['branch_id' => $this->branch->id, 'first_name' => 'Ama', 'last_name' => 'M', 'gender' => 'female']);
        $m2 = Member::create(['branch_id' => $this->branch->id, 'first_name' => 'Kofi', 'last_name' => 'B', 'gender' => 'male']);
        $choir->members()->attach($m1->id, ['role' => 'member', 'joined_at' => now()->toDateString()]);
        $choir->members()->attach($m2->id, ['role' => 'member', 'joined_at' => now()->toDateString()]);

        $token = $this->token($leader);

        // Open a meeting
        $session = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/attendance/sessions', [
                'service_type_id' => $this->deptMeetingType->id,
                'department_id' => $choir->id,
                'service_date' => now()->toDateString(),
            ])->json('data.id');

        // Mark 1 of 2 present
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/attendance/sessions/{$session}/mark", [
                'records' => [
                    ['person_id' => $m1->id, 'type' => 'member', 'is_present' => true],
                    ['person_id' => $m2->id, 'type' => 'member', 'is_present' => false],
                ],
            ])->assertOk();

        // Dashboard should now show 1 present, 50% rate, 1 meeting this month
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.departments.0.attendance.last_present', 1)
            ->assertJsonPath('data.departments.0.attendance.attendance_rate', 50)
            ->assertJsonPath('data.departments.0.attendance.meetings_this_month', 1);
    }
}
