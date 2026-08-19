<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use App\Enums\MoMoNetwork;
use App\Enums\PaymentChannel;
use App\Enums\PaymentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InitializePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Allow both authenticated users (admin giving on behalf) and
        // anonymous/public giving (no auth, for the /give page).
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_type' => ['required', Rule::in(array_column(PaymentType::cases(), 'value'))],
            'amount' => ['required', 'numeric', 'min:1', 'max:9999999.99'],
            'currency' => ['nullable', 'string', 'size:3', 'in:GHS'],
            'channel' => ['required', Rule::in(array_column(PaymentChannel::cases(), 'value'))],
            'momo_network' => [
                'required_if:channel,momo',
                'nullable',
                Rule::in(array_column(MoMoNetwork::cases(), 'value')),
            ],
            'momo_number' => [
                'required_if:channel,momo',
                'nullable',
                'string',
                'min:9',
                'max:20',
            ],
            'member_id' => ['nullable', 'uuid'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
