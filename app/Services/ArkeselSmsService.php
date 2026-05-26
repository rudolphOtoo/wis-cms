<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ArkeselSmsService
{
    /**
     * Send a single SMS via Arkesel.
     *
     * Returns true on success, false on failure (logged). Never throws —
     * callers treat a false return as a delivery failure for that recipient.
     */
    public function send(string $phone, string $message): bool
    {
        $apiKey = config('services.arkesel.api_key');

        // No key configured yet (dev / pre-launch): log and report failure
        // honestly rather than pretending the SMS went out.
        if (! $apiKey) {
            Log::warning("Arkesel API key not configured — SMS to {$phone} not sent.");

            return false;
        }

        try {
            $response = Http::withHeaders(['api-key' => $apiKey])
                ->asJson()
                ->post(rtrim(config('services.arkesel.base_url'), '/').'/sms/send', [
                    'sender' => config('services.arkesel.sender_id'),
                    'message' => $message,
                    'recipients' => [$this->normalise($phone)],
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error("Arkesel SMS failed for {$phone}: ".$response->status().' '.$response->body());

            return false;
        } catch (\Throwable $e) {
            Log::error("Arkesel SMS error for {$phone}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Normalise a Ghanaian number to international format (233...).
     * 0241234567 -> 233241234567 ; leaves already-international numbers alone.
     */
    protected function normalise(string $phone): string
    {
        $p = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($p, '0')) {
            return '233'.substr($p, 1);
        }

        return $p;
    }
}
