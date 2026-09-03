<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSession
{
    /**
     * Reject requests when the session has been idle longer than
     * SESSION_LIFETIME. This acts as a server-side safety net on top of
     * the frontend idle timer.
     *
     * Only enforced when the session contains a 'last_activity_at'
     * timestamp (set by KeepAliveController). Sessions without it
     * pass through to avoid breaking existing sessions created before
     * this feature was deployed.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $lastActivityAt = $request->session()->get('last_activity_at');

        if ($lastActivityAt !== null) {
            $lifetimeMinutes = (int) config('session.lifetime', 15);
            $expiresAt = $lastActivityAt + ($lifetimeMinutes * 60);

            if (now()->timestamp > $expiresAt) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->json([
                    'message' => 'Session expired due to inactivity.',
                ], 401);
            }
        }

        return $next($request);
    }
}
