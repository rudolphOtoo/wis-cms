<?php

namespace App\Http\Requests\Children;

use Illuminate\Foundation\Http\FormRequest;

class StoreChildrenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create children') ?? false;
    }

    public function rules(): array
    {
        return [
            'guardian_member_id' => ['required', 'uuid', 'exists:members,id'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'gender' => ['required', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'class_group' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
