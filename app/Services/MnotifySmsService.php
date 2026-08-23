<?php

namespace App\Services;

use App\Exceptions\TransientSmsException;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
 * Endpoints:
 *   - POST /sms/quick           — immediate OR scheduled single/bulk SMS
 *   - GET  /scheduled           — list all scheduled SMS jobs
 *   - POST /scheduled/{id}      — update a scheduled SMS
 *   - DELETE /scheduled/{id}    — cancel a scheduled SMS
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
     * Send a single SMS via mNotify (immediate delivery).
     *
     * @throws TransientSmsException when the failure is retry-worthy
     */
    public function send(string $phone, string $message): bool
    {
        if ($this->shouldDryRun($phone, $message)) {
            return true;
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return false;
        }

        $response = $this->postSmsQuick($apiKey, $phone, $message, false);

        return $this->evaluateSendResponse($response, $phone);
    }

    /**
     * Schedule an SMS for future delivery via mNotify.
     *
     * Returns the mNotify job ID (string) on success for local tracking.
     * Returns null on permanent failure (bad request, auth error).
     * Throws TransientSmsException on retry-worthy network/server errors.
     *
     * @throws TransientSmsException when the failure is retry-worthy
     */
    public function schedule(string $phone, string $message, Carbon $scheduledAt): ?string
    {
        if ($this->shouldDryRun($phone, $message)) {
            return 'dry-run-'.bin2hex(random_bytes(4));
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return null;
        }

        $response = $this->postSmsQuick($apiKey, $phone, $message, true, $scheduledAt);

        return $this->evaluateScheduleResponse($response, $phone);
    }

    /**
     * Cancel a previously scheduled SMS on mNotify.
     *
     * Returns true on success, false on permanent failure.
     * Throws TransientSmsException on retry-worthy errors.
     *
     * @throws TransientSmsException when the failure is retry-worthy
     */
    public function cancelScheduled(string $mnotifyJobId): bool
    {
        if ($this->isDryRunMode()) {
            Log::info("[DEV DRY-RUN] Cancel scheduled SMS #{$mnotifyJobId}");

            return true;
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return false;
        }

        $endpoint = rtrim(config('services.mnotify.base_url'), '/')."/scheduled/{$mnotifyJobId}?key={$apiKey}";

        try {
            $response = Http::timeout(15)->delete($endpoint);
        } catch (ConnectionException $e) {
            Log::warning("mNotify network failure cancelling #{$mnotifyJobId}: ".$e->getMessage());
            throw new TransientSmsException('mNotify network failure: '.$e->getMessage(), 0, $e);
        }

        if ($response->serverError()) {
            throw new TransientSmsException('mNotify server error: HTTP '.$response->status());
        }

        if (! $response->successful()) {
            Log::error("mNotify cancel rejected (HTTP {$response->status()}) for #{$mnotifyJobId}: ".$response->body());

            return false;
        }

        $body = $response->json();

        return ($body['status'] ?? null) === 'success';
    }

    /**
     * Update a previously scheduled SMS on mNotify (change message
     * body and/or schedule datetime).
     *
     * Returns true on success, false on permanent failure.
     * Throws TransientSmsException on retry-worthy errors.
     *
     * @throws TransientSmsException when the failure is retry-worthy
     */
    public function updateScheduled(string $mnotifyJobId, string $phone, string $message, Carbon $scheduledAt): bool
    {
        if ($this->isDryRunMode()) {
            Log::info("[DEV DRY-RUN] Update scheduled SMS #{$mnotifyJobId} for {$phone}");

            return true;
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return false;
        }

        $endpoint = rtrim(config('services.mnotify.base_url'), '/')."/scheduled/{$mnotifyJobId}?key={$apiKey}";

        try {
            $response = Http::asJson()
                ->timeout(15)
                ->post($endpoint, [
                    'sender' => config('services.mnotify.sender_id'),
                    'message' => $message,
                    'schedule_date' => $scheduledAt->format('Y-m-d H:i'),
                ]);
        } catch (ConnectionException $e) {
            Log::warning("mNotify network failure updating #{$mnotifyJobId}: ".$e->getMessage());
            throw new TransientSmsException('mNotify network failure: '.$e->getMessage(), 0, $e);
        }

        if ($response->serverError()) {
            throw new TransientSmsException('mNotify server error: HTTP '.$response->status());
        }

        if (! $response->successful()) {
            Log::error("mNotify update rejected (HTTP {$response->status()}) for #{$mnotifyJobId}: ".$response->body());

            return false;
        }

        $body = $response->json();

        return ($body['status'] ?? null) === 'success';
    }

    // ─── Internal helpers ─────────────────────────────────────────

    /**
     * Build and send the POST /sms/quick request.
     */
    protected function postSmsQuick(
        string $apiKey,
        string $phone,
        string $message,
        bool $isSchedule,
        ?Carbon $scheduledAt = null,
    ): Response {
        $endpoint = rtrim(config('services.mnotify.base_url'), '/').'/sms/quick?key='.$apiKey;

        try {
            return Http::asJson()
                ->timeout(15)
                ->post($endpoint, [
                    'recipient' => [$this->normalise($phone)],
                    'sender' => config('services.mnotify.sender_id'),
                    'message' => $message,
                    'is_schedule' => $isSchedule,
                    'schedule_date' => $isSchedule && $scheduledAt
                        ? $scheduledAt->format('Y-m-d H:i')
                        : '',
                ]);
        } catch (ConnectionException $e) {
            Log::warning("mNotify transient network failure for {$phone}: ".$e->getMessage());
            throw new TransientSmsException(
                'mNotify network failure: '.$e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Evaluate the response from an immediate send request.
     */
    protected function evaluateSendResponse(Response $response, string $phone): bool
    {
        if ($response->serverError()) {
            Log::warning("mNotify HTTP {$response->status()} for {$phone}: ".$response->body());
            throw new TransientSmsException('mNotify server error: HTTP '.$response->status());
        }

        if (! $response->successful()) {
            Log::error("mNotify SMS rejected (HTTP {$response->status()}) for {$phone}: ".$response->body());

            return false;
        }

        $body = $response->json();
        if (($body['status'] ?? null) === 'success') {
            return true;
        }

        Log::error("mNotify SMS rejected by provider for {$phone}: ".json_encode($body));

        return false;
    }

    /**
     * Evaluate the response from a schedule request.
     *
     * Extracts the mNotify job ID from the response summary.
     */
    protected function evaluateScheduleResponse(Response $response, string $phone): ?string
    {
        if ($response->serverError()) {
            Log::warning("mNotify HTTP {$response->status()} scheduling for {$phone}: ".$response->body());
            throw new TransientSmsException('mNotify server error: HTTP '.$response->status());
        }

        if (! $response->successful()) {
            Log::error("mNotify schedule rejected (HTTP {$response->status()}) for {$phone}: ".$response->body());

            return null;
        }

        $body = $response->json();
        if (($body['status'] ?? null) === 'success') {
            // mNotify returns the scheduled job ID in summary._id (V2 API),
            // with legacy shapes falling back to id / summary.id.
            $summary = $body['summary'] ?? null;
            $jobId = null;

            if (is_array($summary)) {
                // Bulk responses wrap jobs in a list: summary[0]._id
                if (array_is_list($summary)) {
                    $jobId = $summary[0]['_id']
                        ?? $summary[0]['id']
                        ?? $summary[0]['job_id']
                        ?? null;
                } else {
                    $jobId = $summary['_id']
                        ?? $summary['id']
                        ?? $summary['job_id']
                        ?? null;
                }
            }

            $jobId = $jobId
                ?? ($body['_id'] ?? null)
                ?? ($body['id'] ?? null)
                ?? ($body['job_id'] ?? null)
                ?? ($body['data']['id'] ?? null)
                ?? ($body['data']['_id'] ?? null);

            if ($jobId !== null) {
                return (string) $jobId;
            }

            // Accepted but no parseable job ID — log the raw body so new
            // response shapes surface in logs instead of silent failures.
            Log::warning('mNotify schedule accepted but no job ID found in response', [
                'phone' => $phone,
                'response_body' => $response->body(),
            ]);

            return '';
        }

        Log::error("mNotify schedule rejected by provider for {$phone}: ".json_encode($body));

        return null;
    }

    /**
     * Determine if a dry-run should intercept this send.
     */
    protected function shouldDryRun(string $phone, string $message): bool
    {
        if (config('services.mnotify.dry_run', app()->environment('local', 'testing'))) {
            Log::info("[DEV DRY-RUN] SMS intercepted for {$phone}: {$message}");

            return true;
        }

        return false;
    }

    /**
     * Check if the service is in dry-run mode (without phone/message context).
     */
    protected function isDryRunMode(): bool
    {
        return config('services.mnotify.dry_run', app()->environment('local', 'testing'));
    }

    /**
     * Get the API key, logging a warning if missing.
     */
    protected function getApiKey(): ?string
    {
        $apiKey = config('services.mnotify.api_key');

        if (! $apiKey) {
            Log::warning('mNotify API key not configured — SMS not sent.');

            return null;
        }

        return $apiKey;
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
