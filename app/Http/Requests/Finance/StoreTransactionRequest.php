<?php

declare(strict_types=1);

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
{
    /**
     * SMELL-05 FIX: authorize() now checks the actual permission rather than
     * unconditionally returning true. The route middleware already gates this,
     * but the Form Request is a second line of defence — useful when this
     * class is reused outside the standard route stack (tests, artisan, etc.).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create transactions') ?? false;
    }

    public function rules(): array
    {
        return [
            // CRITICAL-01 FIX: Rule::exists() with branch_id scope prevents
            // a user from referencing a member from another branch.
            'member_id' => [
                'nullable',
                'uuid',
                Rule::exists('members', 'id')
                    ->where('branch_id', $this->user()->branch_id)
                    ->whereNull('deleted_at'),
            ],

            // Scoped to active categories only — prevents recording against
            // a retired category that still exists in the DB.
            'category_id' => [
                'required',
                'uuid',
                Rule::exists('finance_categories', 'id')->where('is_active', true),
            ],

            'type' => ['required', 'in:income,expense'],

            // MEDIUM-02 FIX: 'max:9999999.99' closes the door on runaway
            // amounts that pass numeric validation but are clearly data errors.
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999.99'],

            // MEDIUM-01 FIX: currency is validated HERE so controllers can
            // rely exclusively on $request->validated() — no more overriding
            // with raw $request->get() calls.
            'currency' => ['nullable', 'string', 'size:3', 'in:GHS,USD,EUR,GBP'],

            // Prevent future-dated transactions being recorded.
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],

            'reference' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Inject the default currency before validation so that
     * $request->validated()['currency'] is always present.
     * Controllers must NOT call $request->get('currency') — use
     * $request->validated() exclusively.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('currency') || $this->input('currency') === null) {
            $this->merge(['currency' => 'GHS']);
        }
    }
}
