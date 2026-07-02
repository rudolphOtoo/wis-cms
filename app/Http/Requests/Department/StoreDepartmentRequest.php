<?php

namespace App\Http\Requests\Department;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create departments') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'leader_user_id' => [
                'nullable',
                'uuid',
                Rule::exists('users', 'id')
                    ->where('branch_id', $this->user()->branch_id),
            ],
            'is_active' => ['boolean'],
        ];
    }
}
