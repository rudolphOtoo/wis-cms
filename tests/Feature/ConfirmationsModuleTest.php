<?php

namespace Tests\Feature;

use App\Diocese\Modules\Confirmations\Providers\ConfirmationsServiceProvider;
use App\Models\Branch;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ConfirmationsModuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Confirmations module is a reference extensibility proof: while
     * `capabilities.modules.confirmations` is off (the default in both
     * profiles) the module provider is never registered, so no table is
     * migrated and every endpoint returns 404.
     */
    public function test_disabled_module_has_no_table_and_routes_404(): void
    {
        $this->assertFalse(Schema::hasTable('confirmations'));

        $this->getJson('/api/confirmations')
            ->assertNotFound()
            ->assertJson(['message' => 'Route not found.']);
    }

    /**
     * Flipping the flag on in a profile activates the whole package:
     * the module provider registers, its migration creates the table, and
     * the routes respond. Unauthenticated hits prove the route exists
     * (auth middleware → 401) rather than 404.
     */
    public function test_enabling_module_activates_schema_and_routes(): void
    {
        config(['diocese.capabilities.modules.confirmations' => true]);
        $this->app->register(ConfirmationsServiceProvider::class);

        Artisan::call('migrate', [
            '--path' => 'app/Diocese/Modules/Confirmations/Database/Migrations',
            '--force' => true,
        ]);

        $this->assertTrue(Schema::hasTable('confirmations'));

        $this->getJson('/api/confirmations')->assertUnauthorized();
    }

    /**
     * End-to-end: a secretary can record a confirmation for a member of
     * their own branch once the module is enabled.
     */
    public function test_secretary_can_record_a_confirmation_when_module_enabled(): void
    {
        config(['diocese.capabilities.modules.confirmations' => true]);
        $this->app->register(ConfirmationsServiceProvider::class);

        Artisan::call('migrate', [
            '--path' => 'app/Diocese/Modules/Confirmations/Database/Migrations',
            '--force' => true,
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $branch = Branch::factory()->create();

        $user = User::create([
            'branch_id' => $branch->id,
            'name' => 'Secretary',
            'email' => 'secretary@test.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ]);
        $user->assignRole('secretary');

        $member = Member::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/confirmations', [
                'member_id' => $member->id,
                'confirmed_at' => '2026-08-01',
                'officiating_clergy' => 'Rev. John Mensah',
                'location' => 'Bethel Methodist Church',
            ])
            ->assertCreated()
            ->assertJsonPath('data.member.member_number', $member->member_number);

        $this->assertDatabaseHas('confirmations', [
            'member_id' => $member->id,
            'confirmed_at' => '2026-08-01',
        ]);
    }
}
