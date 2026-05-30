<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
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

    protected function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    public function test_flagged_user_is_blocked_from_normal_routes_with_423(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);
        $token = $this->tokenFor($user);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/dashboard');

        $response->assertStatus(423)
            ->assertJsonPath('must_change_password', true);
    }

    public function test_flagged_user_can_still_reach_me_and_change_password(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);
        $token = $this->tokenFor($user);

        // /auth/me must stay reachable so the frontend learns the flag state
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);

        // change-password must stay reachable so the user can escape the lock
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/change-password', [
                'current_password' => 'Password@123',
                'new_password' => 'NewPassword@456',
                'new_password_confirmation' => 'NewPassword@456',
            ])
            ->assertOk();
    }

    public function test_changing_password_clears_the_flag_and_unlocks_routes(): void
    {
        $user = $this->makeUser(['must_change_password' => true]);
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/change-password', [
                'current_password' => 'Password@123',
                'new_password' => 'NewPassword@456',
                'new_password_confirmation' => 'NewPassword@456',
            ])
            ->assertOk();

        // Flag cleared in DB
        $this->assertFalse($user->fresh()->must_change_password);

        // Same token can now reach a normal route
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    public function test_unflagged_user_is_not_blocked(): void
    {
        $user = $this->makeUser(); // flag defaults to false
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk();
    }
}
