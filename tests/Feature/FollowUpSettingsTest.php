<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FollowUpSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->branch = Branch::factory()->create([
            'follow_up_enabled' => true,
            'follow_up_delay_hours' => 2,
            'follow_up_present_template' => 'Hi {name}, glad you joined us!',
            'follow_up_absent_template' => 'Hi {name}, we missed you today.',
        ]);

        $this->admin = User::create([
            'branch_id' => $this->branch->id,
            'name' => 'Admin',
            'email' => 'admin@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $this->admin->assignRole('super_admin');
    }

    private function token(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'follow_up_enabled' => true,
            'follow_up_delay_hours' => 3,
            'follow_up_present_template' => 'Hello {name}, thank you for coming!',
            'follow_up_absent_template' => 'Hello {name}, we hope to see you soon!',
        ], $overrides);
    }

    // ── GET /api/settings/follow-up ────────────────────────────────────────

    public function test_admin_can_read_follow_up_settings(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token($this->admin)}")
            ->getJson('/api/settings/follow-up')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'follow_up_enabled',
                'follow_up_delay_hours',
                'follow_up_present_template',
                'follow_up_absent_template',
            ]]);

        $data = $response->json('data');
        $this->assertTrue($data['follow_up_enabled']);
        $this->assertEquals(2, $data['follow_up_delay_hours']);
        $this->assertEquals('Hi {name}, glad you joined us!', $data['follow_up_present_template']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/settings/follow-up')
            ->assertUnauthorized();
    }

    public function test_pastor_cannot_read_settings(): void
    {
        $pastor = User::create([
            'branch_id' => $this->branch->id, 'name' => 'Pastor',
            'email' => 'pastor@test.local', 'password' => Hash::make('Password@123'), 'is_active' => true,
        ]);
        $pastor->assignRole('pastor');

        $this->withHeader('Authorization', "Bearer {$this->token($pastor)}")
            ->getJson('/api/settings/follow-up')
            ->assertForbidden();
    }

    // ── PUT /api/settings/follow-up ────────────────────────────────────────

    public function test_admin_can_update_follow_up_settings(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->admin)}")
            ->putJson('/api/settings/follow-up', $this->validPayload())
            ->assertOk()
            ->assertJsonPath('data.follow_up_delay_hours', 3)
            ->assertJsonPath('data.follow_up_present_template', 'Hello {name}, thank you for coming!');

        $this->assertDatabaseHas('branches', [
            'id' => $this->branch->id,
            'follow_up_delay_hours' => 3,
        ]);
    }

    public function test_update_persists_disabled_state(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->admin)}")
            ->putJson('/api/settings/follow-up', $this->validPayload(['follow_up_enabled' => false]))
            ->assertOk()
            ->assertJsonPath('data.follow_up_enabled', false);
    }

    public function test_update_rejects_delay_hours_below_minimum(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->admin)}")
            ->putJson('/api/settings/follow-up', $this->validPayload(['follow_up_delay_hours' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['follow_up_delay_hours']);
    }

    public function test_update_rejects_delay_hours_above_maximum(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->admin)}")
            ->putJson('/api/settings/follow-up', $this->validPayload(['follow_up_delay_hours' => 25]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['follow_up_delay_hours']);
    }

    public function test_update_requires_both_templates(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->admin)}")
            ->putJson('/api/settings/follow-up', $this->validPayload([
                'follow_up_present_template' => null,
                'follow_up_absent_template' => null,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['follow_up_present_template', 'follow_up_absent_template']);
    }

    public function test_pastor_cannot_update_settings(): void
    {
        $pastor = User::create([
            'branch_id' => $this->branch->id, 'name' => 'Pastor',
            'email' => 'pastor@test.local', 'password' => Hash::make('Password@123'), 'is_active' => true,
        ]);
        $pastor->assignRole('pastor');

        $this->withHeader('Authorization', "Bearer {$this->token($pastor)}")
            ->putJson('/api/settings/follow-up', $this->validPayload())
            ->assertForbidden();
    }
}
