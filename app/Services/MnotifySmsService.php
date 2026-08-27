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
            $response = Http::connectTimeout(5)->timeout(10)->delete($endpoint);
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

        return $this->putScheduledUpdate($mnotifyJobId, [
            'sender' => config('services.mnotify.sender_id'),
            'message' => $message,
            // mNotify only accepts schedule times between 7am and 7pm
            'schedule_date' => $scheduledAt->format('Y-m-d H:i'),
        ]);
    }

    /**
     * Defuse a scheduled SMS by pushing its send date far into the
     * future, so it can never fire while it still exists remotely.
     *
     * This is the operational fallback for cancelling jobs while
     * mNotify's DELETE /scheduled/{id} endpoint is unavailable
     * (currently returns HTTP 500 server-side). Credits are only
     * charged on actual dispatch, so a dormant year-2099 job costs
     * nothing and members never receive the message.
     *
     * Returns true on success, false on permanent failure.
     * Throws TransientSmsException on retry-worthy errors.
     *
     * @throws TransientSmsException when the failure is retry-worthy
     */
    public function defuseScheduled(string $mnotifyJobId): bool
    {
        if ($this->isDryRunMode()) {
            Log::info("[DEV DRY-RUN] Defuse scheduled SMS #{$mnotifyJobId}");

            return true;
        }

        return $this->putScheduledUpdate($mnotifyJobId, [
            // mNotify requires sender + message on every update, even
            // when only moving the date.
            'sender' => config('services.mnotify.sender_id'),
            'message' => '(cancelled)',
            // mNotify only accepts schedule times between 7am and 7pm
            'schedule_date' => '2099-12-31 07:00',
        ]);
    }

    /**
     * Resolve the authoritative remote handle for a scheduled SMS.
     *
     * mNotify's scheduling response returns a job reference that does
     * NOT match the numeric `_id` used by GET /scheduled (and required
     * by DELETE/PUT /scheduled/{id}). Cancellations driven by locally
     * stored IDs therefore fail against the live API. This method lists
     * the remote schedule once per process and matches a delivery to
     * its remote row by exact date_time + message body; when several
     * identical messages were pushed in one batch (the normal case for
     * branch-wide reminders), candidates are consumed positionally.
     *
     * Returns null when no unconsumed remote match exists (job already
     * dispatched/purged, or the listing is unreachable).
     */
    public function resolveScheduledJobId(string $messageBody, Carbon $scheduledAt): ?string
    {
        $this->loadRemoteSchedule();

        $dateTime = $scheduledAt->format('Y-m-d H:i:s');
        $needle = trim($messageBody);

        foreach ($this->remoteScheduleCache as $job) {
            if (($job['date_time'] ?? null) !== $dateTime) {
                continue;
            }

            if (trim((string) ($job['message'] ?? '')) !== $needle) {
                continue;
            }

            $id = (string) ($job['_id'] ?? '');

            if ($id !== '' && ! in_array($id, $this->consumedRemoteIds, true)) {
                $this->consumedRemoteIds[] = $id;

                return $id;
            }
        }

        return null;
    }

    /** @var list<array<string, mixed>> */
    protected array $remoteScheduleCache = [];

    protected bool $remoteScheduleLoaded = false;

    /** @var list<string> */
    protected array $consumedRemoteIds = [];

    protected function loadRemoteSchedule(): void
    {
        if ($this->remoteScheduleLoaded) {
            return;
        }

        $this->remoteScheduleLoaded = true;

        try {
            $apiKey = $this->getApiKey();
            if ($apiKey === null) {
                return;
            }

            $endpoint = rtrim(config('services.mnotify.base_url'), '/')."/scheduled?key={$apiKey}";
            $response = Http::retry(3, 200, null, false)->connectTimeout(5)->timeout(10)->get($endpoint);

            if (! $response->successful()) {
                Log::warning('mNotify schedule listing unavailable (HTTP '.$response->status().')');

                return;
            }

            $jobs = $response->json('summary');
            $this->remoteScheduleCache = is_array($jobs) ? array_values($jobs) : [];
        } catch (ConnectionException $e) {
            Log::warning('mNotify schedule listing unreachable: '.$e->getMessage());
        }
    }

    /**
     * Send a PUT update for an existing scheduled SMS.
     *
     * NOTE: per-item operations use PUT /scheduled/{id}. The older
     * POST verb is rejected with HTTP 405 by the current API.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws TransientSmsException when the failure is retry-worthy
     */
    protected function putScheduledUpdate(string $mnotifyJobId, array $payload): bool
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return false;
        }

        $endpoint = rtrim(config('services.mnotify.base_url'), '/')."/scheduled/{$mnotifyJobId}?key={$apiKey}";

        try {
            $response = Http::asJson()
                ->connectTimeout(5)
                ->timeout(10)
                ->put($endpoint, $payload);
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

    // ─── Balance check ──────────────────────────────────────────

    /**
     * Query mNotify for the current account credit balance.
     *
     * Returns the numeric balance (e.g. 152.0) on success.
     * Returns null when the API key is missing, the endpoint is
     * unreachable, or the response cannot be parsed.
     *
     * @throws TransientSmsException on network/server errors (retry-worthy)
     */
    public function checkBalance(): ?float
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return null;
        }

        // mNotify v2 exposes SMS credit balance at /balance/sms (GET).
        $endpoint = rtrim(config('services.mnotify.base_url'), '/')."/balance/sms?key={$apiKey}";

        try {
            $response = Http::retry(3, 200, null, false)->connectTimeout(5)->timeout(10)->get($endpoint);
        } catch (ConnectionException $e) {
            Log::warning('mNotify balance check network failure: '.$e->getMessage());
            throw new TransientSmsException('mNotify network failure: '.$e->getMessage(), 0, $e);
        }

        if ($response->serverError()) {
            throw new TransientSmsException('mNotify server error on balance check: HTTP '.$response->status());
        }

        if (! $response->successful()) {
            Log::error('mNotify balance check rejected (HTTP '.$response->status().'): '.$response->body());

            return null;
        }

        $body = $response->json();

        // mNotify returns balance in summary.balance or top-level balance
        $balance = $body['summary']['balance']
            ?? $body['balance']
            ?? $body['data']['balance']
            ?? null;

        if ($balance === null) {
            Log::warning('mNotify balance response missing balance field', ['response' => $body]);

            return null;
        }

        return (float) $balance;
    }

    /**
     * Estimate the number of SMS credits required for a batch of messages.
     *
     * Detects encoding per-message:
     *   - GSM 7-bit (Latin + basic symbols): 160 chars/part1, 153/part 2+
     *   - UCS-2 (emojis, non-Latin scripts):  70 chars/part1,  67/part 2+
     *
     * Church templates frequently contain emojis (🙏🏽, ⛪, ❤️) which
     * force UCS-2 encoding. Using GSM limits for UCS-2 messages would
     * undercount segments and cause mid-batch credit depletion.
     *
     * Returns the total segments across all messages.
     */
    public function estimateCredits(array $messages): int
    {
        $totalSegments = 0;

        foreach ($messages as $message) {
            $totalSegments += $this->estimateMessageSegments($message);
        }

        return $totalSegments;
    }

    /**
     * Query mNotify for delivery reports.
     *
     * Returns a flat array of campaign/sending records, each keyed by _id
     * with fields including 'status', 'message', 'date_time', 'phone', etc.
     *
     * Returns [] on network/decode failure (fail-open for reconciliation).
     *
     * @see https://developer.mnotify.com/api/campaigns#tag/Campaigns/operation/getCampaigns
     */
    public function fetchDeliveryReports(): array
    {
        $apiKey = config('services.mnotify.api_key');
        if (! $apiKey) {
            return [];
        }

        $url = config('services.mnotify.base_url', 'https://api.mnotify.com/api').'/reports/campaigns';

        try {
            $response = Http::retry(3, 200, null, false)->connectTimeout(5)->timeout(10)->get($url, [
                'key' => $apiKey,
            ]);

            if ($response->failed()) {
                return [];
            }

            $payload = $response->json();

            // mNotify wraps results in 'summary' or returns a flat array
            return $payload['summary'] ?? (is_array($payload) ? $payload : []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Estimate the number of SMS segments for a single message,
     * selecting GSM 7-bit or UCS-2 limits based on content.
     */
    protected function estimateMessageSegments(string $message): int
    {
        $length = mb_strlen($message);
        $isUcs2 = $this->isUcs2Message($message);

        if ($isUcs2) {
            // UCS-2 encoding: 70 chars per single segment, 67 for multi-part
            return $length <= 70 ? 1 : (int) ceil($length / 67);
        }

        // GSM 7-bit encoding: 160 chars per single segment, 153 for multi-part
        return $length <= 160 ? 1 : (int) ceil($length / 153);
    }

    /**
     * Detect whether a message requires UCS-2 encoding.
     *
     * Returns true if ANY character falls outside the GSM 7-bit
     * default alphabet + extension table. Common non-GSM characters
     * in church SMS: emojis (🙏🏽, ❤️, ⛪), em-dash (—), smart quotes.
     *
     * GSM 7-bit default charset: @£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞ
     *   ÆæßÉ !"#¤%&'()*+,-./0123456789:;<=>? ¡ABCDEFGHIJKLMNOPQRSTUVWXYZ
     * GSM 7-bit extension table (each counted as2 length units):
     *   ^{}\[~]|€
     */
    public function isUcs2Message(string $message): bool
    {
        // Single-byte ASCII subset of GSM (fast path for pure-ASCII messages)
        if (preg_match('/^[\x00-\x7F]*$/', $message)) {
            return false;
        }

        $gsmDefault = '@£$¥èéùìòÇØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $gsmExtended = '^{}[~]|€';

        $len = mb_strlen($message);
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($message, $i, 1);
            if (strpos($gsmDefault, $char) === false && strpos($gsmExtended, $char) === false) {
                return true;
            }
        }

        return false;
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
                ->connectTimeout(5)
                ->timeout(10)
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
