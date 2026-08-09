<?php

namespace App\Http\Requests\LifeEvent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLifeEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage life events') ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'required', 'in:death,birth'],
            'event_date' => ['sometimes', 'required', 'date'],
            'burial_date' => ['nullable', 'date', 'after_or_equal:event_date'],
            'member_id' => [
                'nullable',
                'uuid',
                Rule::exists('members', 'id')
                    ->where('branch_id', $this->user()->branch_id)
                    ->whereNull('deleted_at'),
            ],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'father_first_name' => ['nullable', 'string', 'max:100'],
            'father_last_name' => ['nullable', 'string', 'max:100'],
            'mother_first_name' => [
                Rule::requiredIf(fn () => $this->input('type') === 'birth'),
                'nullable',
                'string',
                'max:100',
            ],
            'mother_last_name' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
