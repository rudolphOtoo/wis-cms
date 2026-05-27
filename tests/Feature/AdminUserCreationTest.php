<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserCreationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->branch = Branch::factory()->create();
        $this->admin = User::create([
            'branch_id' => $this->branch->id, 'name' => 'Admin',
            'email' => 'admin@wis-cms.local', 'password' => Hash::make('Admin@12345'),
            'is_active' => true,
        ]);
        $this->admin->assignRole('super_admin');
    }

    protected function token(User $u): string
    {
        return $u->createToken('test')->plainTextToken;
    }

    public function test_creating_a_user_returns_a_temp_password_once(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->token($this->admin)}")
            ->postJson('/api/users', [
                'name' => 'New Secretary', 'email' => 'sec@wis-cms.local', 'role' => 'secretary',
            ]);

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['id', 'email'], 'temp_password']);

        $temp = $response->json('temp_password');
        $this->assertIsString($temp);
        $this->assertGreaterThanOrEqual(12, strlen($temp));

        $created = User::where('email', 'sec@wis-cms.local')->first();
        $this->assertTrue(Hash::check($temp, $created->password), 'Temp password should authenticate against stored hash');
        $this->assertTrue($created->must_change_password, 'New user must be flagged to change password');
    }

    public function test_create_no_longer_requires_password_in_payload(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token($this->admin)}")
            ->postJson('/api/users', [
                'name' => 'No Password Field', 'email' => 'nopw@wis-cms.local', 'role' => 'usher',
            ])
            ->assertCreated();
    }
}
