<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $key = $this->throttleKey($request);

        // Block after 5 failed attempts in 60 seconds
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages([
                'email' => ["Too many login attempts. Please try again in {$seconds} seconds."],
            ])->status(429);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            RateLimiter::hit($key, 60); // 60 second decay window

            throw ValidationException::withMessages([
                'email' => ['The credentials you entered are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated. Please contact the administrator.'],
            ]);
        }

        // Success — clear rate limit counter
        RateLimiter::clear($key);

        $user->update(['last_login_at' => now()]);
        $user->tokens()->delete();
        $token = $user->createToken('wis-cms-token')->plainTextToken;

        activity()->causedBy($user)->log('User logged in');

        return response()->json([
            'message' => 'Login successful.',
            'token'   => $token,
            'user'    => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        activity()->causedBy($request->user())->log('User logged out');
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'You have been logged out successfully.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'     => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password you entered is incorrect.'],
            ]);
        }

        $request->user()->update([
            'password' => Hash::make($request->new_password),
        ]);

        activity()->causedBy($request->user())->log('User changed their password');

        return response()->json(['message' => 'Password changed successfully.']);
    }

    /**
     * Generate a unique rate-limit key per email + IP address.
     * This stops attackers from locking out real users from a different IP.
     */
    protected function throttleKey(Request $request): string
    {
        return Str::lower($request->input('email')) . '|' . $request->ip();
    }
}
