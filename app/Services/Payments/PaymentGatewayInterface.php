<?php

declare(strict_types=1);

namespace App\Services\Payments;

interface PaymentGatewayInterface
{
    /**
     * Initialize a payment with the gateway provider.
     *
     * @param  array{
     *   email: string,
     *   amount: float,
     *   currency: string,
     *   reference: string,
     *   channel: string,
     *   momo_network?: string,
     *   momo_number?: string,
     *   metadata?: array,
     * }  $payload
     * @return array{reference: string, status: string, display_text: string}
     */
    public function initializePayment(array $payload): array;

    /**
     * Verify the status of a transaction by its reference.
     *
     * @param  string  $reference  The internal payment reference.
     * @return array{status: string, gateway_response: array, paid_at: ?string}
     */
    public function verifyTransaction(string $reference): array;

    /**
     * Parse and validate an inbound webhook payload.
     *
     * @param  array{body: string, signature: string}  $payload
     * @return array{event: string, reference: string, status: string, data: array}
     *
     * @throws \InvalidArgumentException When signature is invalid.
     */
    public function handleWebhook(array $payload): array;
}
