<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit transactions') ?? false;
    }

    public function rules(): array
    {
        return [
            // CRITICAL-01 FIX: branch-scoped member_id validation.
            'member_id' => [
                'nullable',
                'uuid',
                Rule::exists('members', 'id')
                    ->where('branch_id', $this->user()->branch_id)
                    ->whereNull('deleted_at'),
            ],

            'category_id' => [
                'sometimes',
                'required',
                'uuid',
                Rule::exists('finance_categories', 'id')->where('is_active', true),
            ],

            'type' => ['sometimes', 'required', 'in:income,expense'],

            // MEDIUM-02 FIX: consistent upper bound.
            'amount' => ['sometimes', 'required', 'numeric', 'min:0.01', 'max:9999999.99'],

            'currency' => ['nullable', 'string', 'size:3', 'in:GHS,USD,EUR,GBP'],

            'transaction_date' => ['sometimes', 'required', 'date', 'before_or_equal:today'],

            'reference' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
