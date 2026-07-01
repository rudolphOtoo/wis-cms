<?php

declare(strict_types=1);

namespace App\Http\Requests\Cell;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCellRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit cells') ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'leader_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'is_active' => ['boolean'],
        ];
    }
}
