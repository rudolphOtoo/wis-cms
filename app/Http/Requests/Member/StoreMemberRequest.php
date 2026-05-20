<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:100'],
            'gender' => ['required', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'marital_status' => ['nullable', 'in:single,married,widowed,divorced'],
            'join_date' => ['nullable', 'date'],
            'is_baptised' => ['boolean'],
            'baptism_date' => ['nullable', 'date'],
            'status' => ['in:active,inactive,transferred,deceased'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
