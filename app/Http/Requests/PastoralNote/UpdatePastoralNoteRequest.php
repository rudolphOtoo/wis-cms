<?php

namespace App\Http\Requests\PastoralNote;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePastoralNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('note'));
    }

    public function rules(): array
    {
        return [
            'category' => ['sometimes', 'string', 'in:pastoral,medical,welfare,general'],
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string'],
            'follow_up_required' => ['sometimes', 'boolean'],
            'follow_up_date' => ['nullable', 'date'],
            'follow_up_completed' => ['sometimes', 'boolean'],
        ];
    }
}
