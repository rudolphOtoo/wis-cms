<?php

namespace App\Http\Requests\Member;

use Illuminate\Foundation\Http\FormRequest;

class MarkMemberDeceasedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit members') ?? false;
    }

    public function rules(): array
    {
        return [
            'date_of_death' => ['required', 'date'],
            'burial_date' => ['nullable', 'date', 'after_or_equal:date_of_death'],
        ];
    }
}
