<?php

namespace App\Http\Requests\LifeEvent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLifeEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage life events') ?? false;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'in:death,birth'],
            'event_date' => ['required', 'date'],
            'burial_date' => ['nullable', 'date', 'after_or_equal:event_date'],
            // Optional for deaths — a person can be recorded by name without
            // a register entry. When given, the member must belong to the
            // recorder's branch.
            'member_id' => [
                'nullable',
                'uuid',
                Rule::exists('members', 'id')
                    ->where('branch_id', $this->user()->branch_id)
                    ->whereNull('deleted_at'),
            ],
            // Deaths: the deceased person's name. Births: the baby's name.
            'first_name' => ['required', 'string', 'max:100'],
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
