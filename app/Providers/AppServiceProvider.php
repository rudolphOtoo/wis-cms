<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Payments\PaymentGatewayManager;
use App\Support\PhoneNormalizer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Point the password-reset email link at the SPA's reset screen,
        // carrying the token + email as query params for the React form.
        ResetPassword::createUrlUsing(function (mixed $notifiable, string $token): string {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return rtrim(config('app.url'), '/')."/reset-password?token={$token}&email={$email}";
        });

        $this->configureRateLimiters();
    }

    /**
     * Register named rate limiters used across the application.
     *
     * Named limiters are referenced in routes via `throttle:<name>` and
     * can be adjusted here without touching route files.
     */
    protected function configureRateLimiters(): void
    {
        // ── Payment webhook ────────────────────────────────────────────────
        //
        // Paystack retries failed webhook deliveries automatically, so we
        // allow generous throughput: 30 requests per minute from any IP.
        RateLimiter::for('payment-webhook', function (Request $request): array {
            return [
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        // ── Password-reset flow ───────────────────────────────────────────────
        //
        // Applied to both POST /auth/forgot-password and /auth/reset-password.
        // Two stacked limits, both must pass:
        //
        // 1. Per-IP / 15 minutes (5 hits): stops a single machine from cycling
        //    through an email list — 5 total reset requests, then locked out.
        //
        // 2. Per-email / 15 minutes (5 hits): stops a distributed attack
        //    (rotating IPs) that keeps targeting the same account. Even if the
        //    attacker rotates IPs, the per-email bucket is exhausted after 5 hits,
        //    preventing a flood of reset emails to one subscriber's inbox.
        //
        // The email key is lowercased and namespaced ('reset-email:') so it
        // cannot collide with any other limiter's cache key.
        RateLimiter::for('auth-password-reset', function (Request $request): array {
            $email = Str::lower((string) $request->input('email', ''));

            return [
                Limit::perMinutes(15, 5)->by($request->ip()),
                Limit::perMinutes(15, 5)->by('reset-email:'.$email),
            ];
        });

        // ── Public member-intake webhook ──────────────────────────────────────
        //
        // Two stacked limits, both must pass:
        //   1. Per-IP / 10 minutes (5 hits): blocks a single machine hammering
        //      the endpoint. A human filling a form needs exactly 1 hit.
        //   2. Per-normalized-phone / 24 hours (3 hits): blocks distributed
        //      attacks rotating IPs while targeting the same phone. The phone is
        //      normalized so '0244123456', '+233244123456', and '233244123456'
        //      all resolve to the same daily bucket.
        RateLimiter::for('intake-webhook', function (Request $request): array {
            $rawPhone = (string) ($request->input('phone', 'unknown'));
            // Normalize before keying so that '0244123456', '+233244123456',
            // and '233244123456' all hit the same bucket. Without this a caller
            // can triple the daily cap by rotating phone format variants.
            $normalizedPhone = PhoneNormalizer::normalize($rawPhone);

            return [
                Limit::perMinutes(10, 5)->by($request->ip()),
                Limit::perDay(3)->by('phone:'.$normalizedPhone),
            ];
        });
    }
}
