<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\User;
use App\Rules\AssignableRole;
use App\Rules\MemberRoleRequiresMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage users') ?? false;
    }

    public function rules(): array
    {
        // Resolve the target user's current member_id so MemberRoleRequiresMember
        // can validate correctly even when member_id is not being changed in
        // this request (the existing link still satisfies the rule).
        $targetMemberId = $this->input('member_id')
            ?? User::find($this->route('id'))?->member_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],

            'email' => [
                'sometimes',
                'required',
                'email',
                'max:150',
                Rule::unique('users', 'email')->ignore($this->route('id')),
            ],

            // MEDIUM-03 FIX: enforce password complexity on admin-set passwords.
            // Environment-aware rules via Password::defaults() — uncompromised()
            // is only enforced in production (or when ENABLE_PWNED_PASSWORD_CHECK=true).
            'password' => [
                'nullable',
                Password::defaults(),
                'confirmed',
            ],

            'role' => [
                'sometimes',
                'required',
                'string',
                Rule::exists('roles', 'name'),
                // HIGH-01 FIX: ceiling check on updates too.
                new AssignableRole($this->user()),
                new MemberRoleRequiresMember($targetMemberId),
            ],

            'is_active' => ['boolean'],
        ];
    }
}
