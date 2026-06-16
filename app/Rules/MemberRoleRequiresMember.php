<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Enforces the architecture-review rule: certain roles imply the user
 * IS a person of the church (member, cell_leader, department_leader)
 * and therefore must be linked to a Member record via users.member_id.
 *
 * Other roles (super_admin, pastor, secretary, finance_officer, usher)
 * may represent external staff or visitors and do not require a link.
 */
class MemberRoleRequiresMember implements ValidationRule
{
    public const MEMBER_TIED_ROLES = [
        'member',
        'cell_leader',
        'department_leader',
    ];

    public function __construct(protected ?string $memberId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array($value, self::MEMBER_TIED_ROLES, true)) {
            return; // staff/external roles — no member link required
        }

        if (empty($this->memberId)) {
            $fail("The {$value} role requires the user to be linked to a Member. Link them to an existing member or create a new member first.");
        }
    }
}
