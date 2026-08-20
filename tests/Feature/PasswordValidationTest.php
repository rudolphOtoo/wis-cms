<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class PasswordValidationTest extends TestCase
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

    // ─── Password::defaults() rule behavior ─────────────────────────────

    public function test_defaults_rule_enforces_minimum_length(): void
    {
        $rule = Password::defaults();

        $validator = \Validator::make(
            ['password' => 'short'],
            ['password' => ['required', $rule]],
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('password', $validator->errors()->toArray());
    }

    public function test_defaults_rule_accepts_valid_password_in_non_production(): void
    {
        config(['services.pwned_password_check' => false]);
        $rule = Password::defaults();

        $validator = \Validator::make(
            ['password' => 'Password123'],
            ['password' => ['required', $rule]],
        );

        $this->assertFalse($validator->fails(), 'A valid password should pass in non-production: '.$validator->errors()->toJson());
    }

    public function test_defaults_rule_requires_mixed_case_and_numbers(): void
    {
        $rule = Password::defaults();

        // All lowercase, no numbers
        $validator = \Validator::make(
            ['password' => 'alllowercase'],
            ['password' => ['required', $rule]],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_change_password_endpoint_accepts_common_password_in_non_production(): void
    {
        config(['services.pwned_password_check' => false]);

        $user = $this->makeUser();
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/change-password', [
                'current_password' => 'Password@123',
                'new_password' => 'NewPass1234',
                'new_password_confirmation' => 'NewPass1234',
            ])
            ->assertOk();
    }

    public function test_change_password_endpoint_rejects_too_short_password(): void
    {
        $user = $this->makeUser();
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/change-password', [
                'current_password' => 'Password@123',
                'new_password' => 'short',
                'new_password_confirmation' => 'short',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_password');
    }

    public function test_change_password_endpoint_rejects_password_without_mixed_case(): void
    {
        $user = $this->makeUser();
        $token = $this->tokenFor($user);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/change-password', [
                'current_password' => 'Password@123',
                'new_password' => 'alllowercase1',
                'new_password_confirmation' => 'alllowercase1',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_password');
    }

    public function test_pwned_check_toggle_can_force_enable(): void
    {
        config(['services.pwned_password_check' => true]);

        $rule = Password::defaults();

        // 'password' is in HaveIBeenPwned — should fail when toggle is on
        $validator = \Validator::make(
            ['password' => 'password123A'],
            ['password' => ['required', $rule]],
        );

        $this->assertTrue($validator->fails(), 'Compromised password should fail when pwned check is forced on');
    }

    public function test_update_user_request_uses_defaults_rule(): void
    {
        config(['services.pwned_password_check' => false]);
        $this->seed(RolesAndPermissionsSeeder::class);

        $branch = Branch::factory()->create();
        $admin = $this->makeUser(['email' => 'admin@wis-cms.local', 'branch_id' => $branch->id]);
        $admin->assignRole('super_admin');
        $token = $this->tokenFor($admin);

        $target = $this->makeUser(['email' => 'target@wis-cms.local', 'branch_id' => $branch->id]);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson("/api/users/{$target->id}", [
                'password' => 'Simple1234',
                'password_confirmation' => 'Simple1234',
            ])
            ->assertOk();
    }
}
