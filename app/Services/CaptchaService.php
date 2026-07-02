<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CAPTCHA verification service.
 *
 * Integrates with Google reCAPTCHA v3 or Cloudflare Turnstile.
 * Returns a score (0.0–1.0) indicating likelihood the request is human.
 */
class CaptchaService
{
    /**
     * Verify a CAPTCHA token.
     *
     * @param  string  $token  The token from the frontend
     * @param  string|null  $remoteIp  Optional IP for additional verification
     * @return bool True if verification passes
     *
     * @throws \Exception If network error or service unavailable
     */
    public static function verify(string $token, ?string $remoteIp = null): bool
    {
        if (! config('services.google_recaptcha.enabled')) {
            Log::debug('CAPTCHA disabled; skipping verification');

            return true;
        }

        if (! config('services.google_recaptcha.secret_key')) {
            Log::warning('CAPTCHA enabled but no secret key configured');

            return true; // Fail open
        }

        try {
            $response = Http::timeout(5)->connectTimeout(3)->asForm()->post(
                'https://www.google.com/recaptcha/api/siteverify',
                [
                    'secret' => config('services.google_recaptcha.secret_key'),
                    'response' => $token,
                    'remoteip' => $remoteIp,
                ]
            );

            if (! $response->successful()) {
                Log::warning('CAPTCHA service returned non-2xx', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            $data = $response->json();

            // v3 returns a score 0.0–1.0
            // 1.0 = definitely human, 0.0 = definitely bot
            $success = $data['success'] ?? false;
            $score = $data['score'] ?? 0;
            $threshold = config('services.google_recaptcha.score_threshold', 0.5);

            Log::debug('CAPTCHA verification result', [
                'success' => $success,
                'score' => $score,
                'threshold' => $threshold,
                'passed' => $success && $score >= $threshold,
            ]);

            return $success && $score >= $threshold;
        } catch (\Throwable $e) {
            Log::error('CAPTCHA verification error', [
                'error' => $e->getMessage(),
                'ip' => $remoteIp,
            ]);

            // Fail open: if CAPTCHA service is down, allow submission
            // but log for investigation
            return true;
        }
    }
}
