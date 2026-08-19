<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\MoMoNetwork;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Paystack payment gateway driver for Ghana Mobile Money.
 *
 * Handles the Paystack Charge API for MoMo (MTN, Telecel, AT),
 * transaction verification via the Verify endpoint, and webhook
 * signature validation (HMAC SHA512).
 *
 * @see https://paystack.com/docs/payments/payment-channels/#mobile-money
 * @see https://paystack.com/docs/payments/webhooks/
 */
class PaystackPaymentDriver implements PaymentGatewayInterface
{
    private string $secretKey;

    private string $webhookSecret;

    private string $baseUrl = 'https://api.paystack.co';

    public function __construct()
    {
        $this->secretKey = (string) config('services.paystack.secret');
        $this->webhookSecret = (string) config('services.paystack.webhook_secret');
    }

    public function initializePayment(array $payload): array
    {
        $body = [
            'email' => $payload['email'],
            'amount' => (int) round($payload['amount'] * 100), // Pesewas
            'currency' => $payload['currency'] ?? 'GHS',
            'reference' => $payload['reference'],
            'metadata' => $payload['metadata'] ?? [],
        ];

        if (($payload['channel'] ?? '') === 'momo') {
            $network = MoMoNetwork::tryFrom($payload['momo_network'] ?? '');
            $body['mobile_money'] = [
                'phone' => $payload['momo_number'],
                'provider' => $network?->paystackProvider() ?? 'mtn',
            ];
        }

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->secretKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post("{$this->baseUrl}/charge", $body);

        $data = $response->json();

        if (! $response->successful() || ! ($data['status'] ?? false)) {
            Log::error('Paystack charge failed', [
                'status' => $response->status(),
                'response' => $data,
                'reference' => $payload['reference'],
            ]);

            throw new \RuntimeException(
                $data['message'] ?? 'Payment initialization failed with gateway.'
            );
        }

        return [
            'reference' => $data['data']['reference'],
            'status' => $data['data']['status'],
            'display_text' => $data['data']['display_text'] ?? '',
        ];
    }

    public function verifyTransaction(string $reference): array
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->secretKey,
        ])->timeout(30)->get("{$this->baseUrl}/transaction/verify/{$reference}");

        $body = $response->json();

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            Log::warning('Paystack verify failed', [
                'status' => $response->status(),
                'reference' => $reference,
                'response' => $body,
            ]);

            return [
                'status' => 'failed',
                'gateway_response' => $body['data'] ?? $body,
                'paid_at' => null,
            ];
        }

        $txData = $body['data'];

        return [
            'status' => $txData['status'] === 'success' ? 'success' : 'failed',
            'gateway_response' => $txData,
            'paid_at' => $txData['paid_at'] ?? null,
        ];
    }

    public function handleWebhook(array $payload): array
    {
        $body = $payload['body'];
        $signature = $payload['signature'];

        // HMAC SHA512 signature verification.
        $computed = hash_hmac('sha512', $body, $this->webhookSecret);

        if (! hash_equals($computed, $signature)) {
            throw new \InvalidArgumentException('Invalid webhook signature.');
        }

        $event = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        $reference = $event['data']['reference'] ?? '';
        $status = $event['data']['status'] ?? '';
        $eventType = $event['event'] ?? '';

        return [
            'event' => $eventType,
            'reference' => $reference,
            'status' => $status,
            'data' => $event['data'] ?? [],
        ];
    }
}
