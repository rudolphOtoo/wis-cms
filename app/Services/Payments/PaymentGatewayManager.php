<?php

declare(strict_types=1);

namespace App\Services\Payments;

/**
 * Resolves the active payment gateway driver.
 *
 * Currently supports Paystack as the sole provider. Additional gateways
 * (Hubtel, Flutterwave, etc.) can be registered here without changing
 * any controller or consumer code.
 */
class PaymentGatewayManager
{
    private array $drivers = [];

    public function __construct()
    {
        $this->drivers = [
            'paystack' => PaystackPaymentDriver::class,
        ];
    }

    /**
     * Resolve a gateway driver by name.
     *
     * @param  string|null  $name  Driver name (e.g. 'paystack'). Falls back to config or 'paystack'.
     */
    public function driver(?string $name = null): PaymentGatewayInterface
    {
        $name = $name ?? config('services.paystack') ? 'paystack' : 'paystack';

        if (! isset($this->drivers[$name])) {
            throw new \InvalidArgumentException("Payment driver [{$name}] is not supported.");
        }

        $class = $this->drivers[$name];

        return new $class;
    }
}
