<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for member intake form submissions.
 * Used by both the public webhook AND admin approval flow.
 *
 * @property string $first_name
 * @property string $last_name
 * @property string $phone Normalized to 0XXXXXXXXX format
 * @property string|null $email
 * @property string $gender
 * @property string|null $date_of_birth
 * @property string|null $address
 * @property string|null $occupation
 * @property string|null $marital_status
 * @property string|null $cell_name
 * @property string|null $captcha_token
 */
class StoreIntakeFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Webhook uses header-based auth, not this.
        // Admin review uses middleware permissions.
        // Return true here — auth is checked elsewhere.
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                'regex:/^[\p{L}\s\-\'\.]+$/u', // Letters, spaces, hyphens, apostrophes, periods
            ],
            'last_name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                'regex:/^[\p{L}\s\-\'\.]+$/u',
            ],
            'phone' => [
                'required',
                'string',
                'min:9',
                'max:20',
                // Intl format: +233xxxxxxxxx, 233xxxxxxxxx, 0xxxxxxxxx
                // Spaces, dashes, parentheses allowed
                'regex:/^(\+|0)?[0-9\s\-\(\)]{8,19}$/',
            ],
            'email' => [
                'nullable',
                'email:rfc,dns',
                'max:255',
            ],
            'gender' => [
                'required',
                'string',
                Rule::in(['male', 'female', 'other']),
            ],
            'date_of_birth' => [
                'nullable',
                'date',
                'before:today',
                'after:1900-01-01',
            ],
            'address' => [
                'nullable',
                'string',
                'max:500',
            ],
            'occupation' => [
                'nullable',
                'string',
                'max:100',
            ],
            'marital_status' => [
                'nullable',
                'string',
                Rule::in(['single', 'married', 'widowed', 'divorced', 'separated']),
            ],
            'cell_name' => [
                'nullable',
                'string',
                'max:100',
            ],
            'captcha_token' => [
                config('services.google_recaptcha.enabled') ? 'required' : 'nullable',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'Please enter your first name.',
            'first_name.min' => 'First name must be at least 2 characters.',
            'first_name.regex' => 'First name contains invalid characters.',
            'last_name.required' => 'Please enter your last name.',
            'last_name.regex' => 'Last name contains invalid characters.',
            'phone.required' => 'Please enter your phone number.',
            'phone.regex' => 'Phone number format is invalid. Try: 0244123456 or +233244123456',
            'gender.required' => 'Please select your gender.',
            'gender.in' => 'Gender must be male, female, or other.',
            'email.email' => 'Please enter a valid email address.',
            'email.max' => 'Email address is too long.',
            'date_of_birth.date' => 'Date of birth must be a valid date.',
            'date_of_birth.before' => 'Date of birth cannot be in the future.',
            'date_of_birth.after' => 'Please enter a realistic date of birth.',
            'marital_status.in' => 'Marital status must be one of: single, married, widowed, divorced, separated.',
            'captcha_token.required' => 'CAPTCHA verification is required.',
        ];
    }

    public function validated(): array
    {
        $data = parent::validated();

        // Normalize phone (belt-and-suspenders)
        $data['phone'] = $this->normalizePhone($data['phone']);

        // Trim whitespace from names
        $data['first_name'] = trim($data['first_name']);
        $data['last_name'] = trim($data['last_name']);

        // Remove captcha_token from validated data (don't store it)
        unset($data['captcha_token']);

        return $data;
    }

    /**
     * Normalize a Ghanaian phone number into local 0XXXXXXXXX format.
     * Handles common variants: +233 prefix, spaces, leading zero missing.
     */
    protected function normalizePhone(string $phone): string
    {
        // Strip all whitespace, dashes, parentheses
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);

        // +233xxxxxxxxx → 0xxxxxxxxx
        if (str_starts_with($phone, '+233')) {
            return '0'.substr($phone, 4);
        }

        // 233xxxxxxxxx → 0xxxxxxxxx (only if exactly 12 chars: 233 + 9 digits)
        if (str_starts_with($phone, '233') && strlen($phone) === 12) {
            return '0'.substr($phone, 3);
        }

        return $phone;
    }
}
