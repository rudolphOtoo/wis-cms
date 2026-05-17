<?php
namespace App\Http\Requests\Visitor;

use Illuminate\Foundation\Http\FormRequest;

class StoreVisitorRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'first_name'     => ['required', 'string', 'max:100'],
            'last_name'      => ['required', 'string', 'max:100'],
            'phone'          => ['nullable', 'string', 'max:20'],
            'email'          => ['nullable', 'email', 'max:150'],
            'address'        => ['nullable', 'string'],
            'how_they_heard' => ['nullable', 'string', 'max:150'],
            'visit_date'     => ['required', 'date'],
            'follow_up_status' => ['in:pending,contacted,not_interested,joined'],
            'notes'          => ['nullable', 'string'],
        ];
    }
}
