<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DepartmentScopeMiddleware
{
    /**
     * Handle department-scoped access control.
     *
     * Department leaders can only access their assigned department
     * Pastoral staff can see their department or be department leaders
     */
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check() && Auth::user()->hasAnyRole(['pastor', 'secretary', 'department_leader'])) {
            $user = Auth::user();

            // If user is a department leader, load their department
            if ($user->hasRole('department_leader')) {
                // Load the department they lead
                // This will be used in the controller to scope queries
                $user->loadMissing(['department' => function ($query) use ($user) {
                    $query->where('leader_user_id', $user->id);
                }]);
            }

            if ($user->hasRole('secretary')) {
                // Secretaries can see the main/pastor's department
                // This is handled at the query level in the controllers
            }
        }

        return $next($request);
    }
}
