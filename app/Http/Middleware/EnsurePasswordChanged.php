<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Block all authenticated actions while the user is flagged to change
     * their password — except the routes needed to change it or sign out.
     * Returns 423 Locked so the frontend can force-redirect.
     */
    protected array $allowed = [
        'api/auth/change-password',
        'api/auth/logout',
        'api/auth/me',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! in_array($request->path(), $this->allowed, true)) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
                'must_change_password' => true,
            ], 423);
        }

        return $next($request);
    }
}
