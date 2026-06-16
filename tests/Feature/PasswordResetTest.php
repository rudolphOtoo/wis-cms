<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
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

    public function test_active_user_receives_reset_notification(): void
    {
        Notification::fake();
        $user = $this->makeUser();

        $this->postJson('/api/auth/forgot-password', ['email' => 'test@wis-cms.local'])
            ->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_email_gets_generic_response_and_no_notification(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@wis-cms.local'])
            ->assertOk()
            ->assertJsonPath('message', 'If that email is registered, a password reset link has been sent.');

        Notification::assertNothingSent();
    }

    public function test_inactive_user_gets_no_reset_notification(): void
    {
        Notification::fake();
        $this->makeUser(['is_active' => false]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'test@wis-cms.local'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    public function test_valid_token_resets_password(): void
    {
        $user = $this->makeUser();
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'test@wis-cms.local',
            'password' => 'BrandNew@789',
            'password_confirmation' => 'BrandNew@789',
        ])->assertOk();

        $this->assertTrue(Hash::check('BrandNew@789', $user->fresh()->password));
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->makeUser();

        $this->postJson('/api/auth/reset-password', [
            'token' => 'totally-invalid-token',
            'email' => 'test@wis-cms.local',
            'password' => 'BrandNew@789',
            'password_confirmation' => 'BrandNew@789',
        ])->assertStatus(422);
    }
}
