<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Rules\AssignableRole;
use App\Rules\MemberRoleRequiresMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage users') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'unique:users,email', 'max:150'],

            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name'),
                // HIGH-01 FIX: prevents privilege escalation.
                new AssignableRole($this->user()),
                // Existing rule: the 'member' role requires a linked member_id.
                new MemberRoleRequiresMember($this->input('member_id')),
            ],

            'is_active' => ['boolean'],

            // CRITICAL-01 FIX: member_id scoped to the assigning user's branch.
            'member_id' => [
                'nullable',
                'uuid',
                Rule::exists('members', 'id')
                    ->where('branch_id', $this->user()->branch_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
