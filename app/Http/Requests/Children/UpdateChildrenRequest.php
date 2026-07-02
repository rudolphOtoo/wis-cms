<?php

namespace App\Http\Requests\Children;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChildrenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit children') ?? false;
    }

    public function rules(): array
    {
        return [
            'guardian_member_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('members', 'id')
                    ->where('branch_id', $this->user()->branch_id)
                    ->whereNull('deleted_at'),
            ],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'gender' => ['sometimes', 'required', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'class_group' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
