<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\SystemAlert;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SystemAlertIndexTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();
        $this->admin = User::create([
            'branch_id' => $branch->id, 'name' => 'Admin',
            'email' => 'admin@wis-cms.local', 'password' => Hash::make('Admin@12345'),
            'is_active' => true,
        ]);
        $this->admin->assignRole('super_admin');
    }

    public function test_alerts_can_be_filtered_by_type(): void
    {
        SystemAlert::create(['type' => SystemAlert::TYPE_CREDIT_DEPLETION, 'title' => 'Low credit', 'message' => 'Top up']);
        SystemAlert::create(['type' => SystemAlert::TYPE_GENERAL, 'title' => 'FYI', 'message' => 'Note']);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/system-alerts?type='.SystemAlert::TYPE_CREDIT_DEPLETION);

        $response->assertOk();
        $this->assertSame(1, $response->json('total'));
        $this->assertSame(SystemAlert::TYPE_CREDIT_DEPLETION, $response->json('data.0.type'));
    }
}
