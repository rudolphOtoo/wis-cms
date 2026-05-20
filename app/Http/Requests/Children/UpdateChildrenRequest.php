<?php

namespace App\Http\Requests\Children;

use Illuminate\Foundation\Http\FormRequest;

class UpdateChildrenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guardian_member_id' => ['sometimes', 'required', 'uuid', 'exists:members,id'],
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
