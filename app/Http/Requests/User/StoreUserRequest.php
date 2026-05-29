<?php

namespace App\Http\Requests\User;

use App\Rules\MemberRoleRequiresMember;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'unique:users,email', 'max:150'],
            'role' => ['required', 'string', 'exists:roles,name', new MemberRoleRequiresMember($this->input('member_id'))],
            'is_active' => ['boolean'],
            'member_id' => ['nullable', 'uuid', 'exists:members,id'],
        ];
    }
}
