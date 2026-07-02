<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BranchScopeMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && Auth::user()->hasAnyRole(['system_admin', 'pastor', 'secretary', 'treasurer'])) {
            $user = Auth::user();

            if ($user->branch_id) {
                $request->merge(['branch_id' => $user->branch_id]);
            }
        }

        return $next($request);
    }
}
