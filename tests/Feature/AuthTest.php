<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function makeUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'branch_id' => Branch::factory()->create()->id,
            'name' => 'Test User',
            'email' => 'test@wis-cms.local',
            'password' => Hash::make('Password@123'),
            'is_active' => true,
        ], $attrs));
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@wis-cms.local',
            'password' => 'Password@123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message', 'token', 'user']);
    }

    public function test_user_cannot_login_with_wrong_password(): void
    {
        $this->makeUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@wis-cms.local',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(422);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->makeUser(['is_active' => false]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@wis-cms.local',
            'password' => 'Password@123',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Your account has been deactivated. Please contact the administrator.');
    }

    public function test_login_is_throttled_after_five_attempts(): void
    {
        $this->makeUser();

        // 5 failed attempts
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'test@wis-cms.local',
                'password' => 'WrongPassword',
            ]);
        }

        // 6th attempt should be throttled
        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@wis-cms.local',
            'password' => 'WrongPassword',
        ]);

        $response->assertStatus(429);
    }

    public function test_authenticated_user_can_fetch_their_profile(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('user.email', 'test@wis-cms.local');
    }
}
