<?php

namespace App\Services;

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
 */
class MnotifySmsService
{
    /**
     * Send a single SMS via mNotify.
     *
     * Returns true on success, false on failure (logged). Never throws —
     * callers treat a false return as a delivery failure for that recipient.
     */
    public function send(string $phone, string $message): bool
    {
        $apiKey = config('services.mnotify.api_key');

        // No key configured (dev / pre-launch): log and report failure
        // honestly rather than pretending the SMS went out.
        if (! $apiKey) {
            Log::warning("mNotify API key not configured — SMS to {$phone} not sent.");

            return false;
        }

        $endpoint = rtrim(config('services.mnotify.base_url'), '/').'/sms/quick?key='.$apiKey;

        try {
            $response = Http::asJson()
                ->post($endpoint, [
                    'recipient' => [$this->normalise($phone)],
                    'sender' => config('services.mnotify.sender_id'),
                    'message' => $message,
                    'is_schedule' => false,
                    'schedule_date' => '',
                ]);

            if ($response->successful()) {
                $body = $response->json();
                // mNotify returns status in the body even on HTTP 200.
                // status:'success' means accepted; other values are failures.
                if (($body['status'] ?? null) === 'success') {
                    return true;
                }
                Log::error("mNotify SMS rejected for {$phone}: ".json_encode($body));

                return false;
            }

            Log::error("mNotify SMS HTTP error for {$phone}: ".$response->status().' '.$response->body());

            return false;
        } catch (\Throwable $e) {
            Log::error("mNotify SMS exception for {$phone}: ".$e->getMessage());

            return false;
        }
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
