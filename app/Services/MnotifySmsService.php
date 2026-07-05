<?php

namespace App\Services;

use App\Exceptions\TransientSmsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS delivery via mNotify (Ghana SMS provider).
 *
 * mNotify's API quirks worth knowing:
 *   - API key passes as URL query param (?key=...), not a header
 *   - 'recipient' is an ARRAY (native bulk support); we pass single-item
 *   - Phone format: Ghana LOCAL ('0241234567'), NOT international
 *   - All HTTP responses are JSON
 *
 * Endpoint: POST https://api.mnotify.com/api/sms/quick
 *
 * Reference: https://readthedocs.mnotify.com
 *
 * RETRY SEMANTICS
 *   - Throws TransientSmsException on HTTP 5xx, connection timeout,
 *     or network error. Caller (queue worker) retries with backoff.
 *   - Returns false on HTTP 4xx, body status:'failed', or missing
 *     credentials. These are permanent failures; no retry helps.
 *   - Returns true on HTTP 200 with body status:'success'.
 */
class MnotifySmsService
{
    /**
     * Send a single SMS via mNotify.
     *
     * @throws TransientSmsException when the failure is retry-worthy
     */
    public function send(string $phone, string $message): bool
    {
        // Safety gate: never fire real SMS from local or testing environments.
        if (app()->environment('local', 'testing')) {
            Log::info("[DEV DRY-RUN] SMS intercepted for {$phone}: {$message}");

            return true;
        }

        $apiKey = config('services.mnotify.api_key');

        // No key configured (dev / pre-launch): permanent failure.
        // Retrying won't help - the key will still be missing.
        if (! $apiKey) {
            Log::warning("mNotify API key not configured — SMS to {$phone} not sent.");

            return false;
        }

        $endpoint = rtrim(config('services.mnotify.base_url'), '/').'/sms/quick?key='.$apiKey;

        try {
            $response = Http::asJson()
                ->timeout(15)
                ->post($endpoint, [
                    'recipient' => [$this->normalise($phone)],
                    'sender' => config('services.mnotify.sender_id'),
                    'message' => $message,
                    'is_schedule' => false,
                    'schedule_date' => '',
                ]);
        } catch (ConnectionException $e) {
            // Network failure / timeout / DNS error - retry-worthy.
            Log::warning("mNotify transient network failure for {$phone}: ".$e->getMessage());
            throw new TransientSmsException(
                'mNotify network failure: '.$e->getMessage(),
                0,
                $e
            );
        }

        // HTTP-level error: 5xx is transient (mNotify backend issue),
        // 4xx is permanent (our request was rejected).
        if ($response->serverError()) {
            Log::warning("mNotify HTTP {$response->status()} for {$phone}: ".$response->body());
            throw new TransientSmsException(
                'mNotify server error: HTTP '.$response->status()
            );
        }

        if (! $response->successful()) {
            // 4xx range - permanent (auth, invalid number, etc.)
            Log::error("mNotify SMS rejected (HTTP {$response->status()}) for {$phone}: ".$response->body());

            return false;
        }

        // 2xx response. mNotify still reports status in body.
        $body = $response->json();
        if (($body['status'] ?? null) === 'success') {
            return true;
        }

        Log::error("mNotify SMS rejected by provider for {$phone}: ".json_encode($body));

        return false;
    }

    /**
     * Normalise to Ghana local format (0241234567).
     * mNotify expects local format, not international.
     *   233241234567   -> 0241234567
     *   +233241234567  -> 0241234567
     *   0241234567     -> 0241234567 (unchanged)
     */
    protected function normalise(string $phone): string
    {
        $p = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($p, '233')) {
            return '0'.substr($p, 3);
        }

        return $p;
    }
}
