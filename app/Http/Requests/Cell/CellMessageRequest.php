<?php

declare(strict_types=1);

namespace App\Http\Requests\Cell;

use Illuminate\Foundation\Http\FormRequest;

class CellMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:1600'],
            'channel' => ['required', 'string', 'in:sms,email,both'],
        ];
    }
}
