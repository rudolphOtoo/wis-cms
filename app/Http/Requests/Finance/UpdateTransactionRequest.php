<?php
namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'category_id'      => ['sometimes', 'required', 'uuid', 'exists:finance_categories,id'],
            'member_id'        => ['nullable', 'uuid', 'exists:members,id'],
            'type'             => ['sometimes', 'required', 'in:income,expense'],
            'amount'           => ['sometimes', 'required', 'numeric', 'min:0.01'],
            'currency'         => ['nullable', 'string', 'max:5'],
            'transaction_date' => ['sometimes', 'required', 'date'],
            'reference'        => ['nullable', 'string', 'max:50'],
            'notes'            => ['nullable', 'string'],
        ];
    }
}
