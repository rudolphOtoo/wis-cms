<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create members') ?? false;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:100'],
            'gender' => ['required', 'in:male,female'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => [
                'nullable', 'string', 'max:20',
                // Matches the DB unique index (branch_id, phone). Postgres
                // treats NULLs as distinct, so multiple members without a
                // phone remain allowed.
                Rule::unique('members', 'phone')
                    ->where(fn ($q) => $q->where('branch_id', $this->user()->branch_id)),
            ],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'marital_status' => ['nullable', 'in:single,married,widowed,divorced'],
            'join_date' => ['nullable', 'date'],
            'is_baptised' => ['boolean'],
            'baptism_date' => ['nullable', 'date'],
            'status' => ['in:active,inactive,transferred,deceased'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
