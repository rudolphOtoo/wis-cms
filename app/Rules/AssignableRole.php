<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ensures the role being assigned does not exceed the assigning user's
 * own privilege ceiling. Prevents privilege escalation: a secretary cannot
 * mint a super_admin, a finance_officer cannot create a pastor, etc.
 *
 * Role hierarchy enforced (highest → lowest):
 *   super_admin → pastor → secretary / finance_officer
 *   → department_leader → cell_leader → member
 *
 * Rule: you may only assign roles EQUAL TO OR BELOW your own tier.
 * super_admins are the sole exception — they can assign any role.
 */
class AssignableRole implements ValidationRule
{
    public function __construct(private readonly User $assigningUser) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array($value, $this->resolveCeiling(), true)) {
            $fail('You do not have permission to assign the ":input" role.');
        }
    }

    /**
     * Returns the exhaustive list of roles the assigning user may grant.
     *
     * @return list<string>
     */
    private function resolveCeiling(): array
    {
        $user = $this->assigningUser;

        if ($user->hasRole('super_admin')) {
            return [
                'super_admin', 'pastor', 'secretary', 'finance_officer',
                'department_leader', 'cell_leader', 'usher', 'member',
            ];
        }

        if ($user->hasRole('pastor')) {
            return ['secretary', 'finance_officer', 'department_leader', 'cell_leader', 'usher', 'member'];
        }

        if ($user->hasAnyRole(['secretary', 'finance_officer'])) {
            return ['department_leader', 'cell_leader', 'usher', 'member'];
        }

        // department_leader / cell_leader / member: cannot create other users
        // (this route is already gated by 'manage users' permission, so this
        // branch is a defence-in-depth fallback).
        return [];
    }
}
