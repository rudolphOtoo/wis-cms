<?php

namespace App\Http\Requests\User;

use App\Models\User;
use App\Rules\MemberRoleRequiresMember;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'email' => ['sometimes', 'required', 'email', 'max:150',
                Rule::unique('users', 'email')->ignore($this->route('id'))],
            'password' => ['nullable', 'string', 'min:8'],
            'role' => ['sometimes', 'required', 'string', 'exists:roles,name',
                new MemberRoleRequiresMember(
                    $this->input('member_id') ?? User::find($this->route('id'))?->member_id
                )],
            'is_active' => ['boolean'],
        ];
    }
}
