<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\TransientSmsException;
use App\Models\MemberSubmission;
use App\Services\MnotifySmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends a "Thank You" acknowledgment SMS to the person who just
 * submitted the member intake form.
 *
 * This job is dispatched immediately after a successful webhook
 * ingestion, with a short delay to allow the DB commit to propagate.
 * It runs on the queue worker — the webhook HTTP response returns to
 * Google Apps Script without waiting for SMS delivery.
 *
 * RETRY SEMANTICS
 *   - $tries = 3: three total delivery attempts.
 *   - $backoff: exponential — 1 min, 5 min, 20 min.
 *   - TransientSmsException (network / 5xx from mNotify): re-thrown to
 *     trigger retry. The queue worker respects the backoff schedule.
 *   - Permanent failures (unconfigured API key, bad number, 4xx from
 *     mNotify): MnotifySmsService returns false rather than throwing.
 *     We log and return without retrying — a retry would produce the
 *     same outcome.
 *   - If all retries are exhausted: the job lands in the failed_jobs
 *     table. Run `php artisan queue:retry all` or inspect via Horizon.
 *
 * GRACEFUL DEGRADATION
 *   - If the submission row has been deleted (admin purge) between
 *     dispatch and execution, the job logs a warning and exits cleanly.
 *   - If the submission has no phone (shouldn't happen given validation,
 *     but defensive), the job exits without attempting delivery.
 */
class SendIntakeThankYouJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Backoff in seconds between retry attempts.
     * Exponential: 60s → 300s → 1200s.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 1200];
    }

    public function __construct(public readonly string $submissionId) {}

    /**
     * Execute the job.
     *
     * MnotifySmsService is resolved from the container so that tests
     * can swap in a fake without touching global state.
     */
    public function handle(MnotifySmsService $sms): void
    {
        $submission = MemberSubmission::find($this->submissionId);

        if ($submission === null) {
            Log::warning('SendIntakeThankYouJob: submission not found — may have been deleted', [
                'submission_id' => $this->submissionId,
                'attempt' => $this->attempts(),
            ]);

            return; // No point retrying if the row is gone.
        }

        if (empty($submission->phone)) {
            Log::warning('SendIntakeThankYouJob: submission has no phone — skipping', [
                'submission_id' => $submission->id,
            ]);

            return;
        }

        $message = $this->buildMessage($submission);

        try {
            $sent = $sms->send($submission->phone, $message);

            if ($sent) {
                Log::info('SendIntakeThankYouJob: thank-you SMS delivered', [
                    'submission_id' => $submission->id,
                    'phone' => $submission->phone,
                ]);
            } else {
                // Permanent failure (4xx, missing API key, provider rejection).
                // MnotifySmsService already logged the detail. Do not retry.
                Log::warning('SendIntakeThankYouJob: SMS delivery permanently failed (no retry)', [
                    'submission_id' => $submission->id,
                    'phone' => $submission->phone,
                    'attempt' => $this->attempts(),
                ]);
            }
        } catch (TransientSmsException $e) {
            // Network error or mNotify 5xx — worth retrying.
            Log::warning('SendIntakeThankYouJob: transient SMS failure, will retry', [
                'submission_id' => $submission->id,
                'phone' => $submission->phone,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw so the queue worker schedules the next attempt.
        }
    }

    /**
     * Build the thank-you SMS body.
     *
     * Kept short to respect SMS character limits (160 chars for GSM-7,
     * fewer for Unicode). The message below is ~155 chars.
     */
    private function buildMessage(MemberSubmission $submission): string
    {
        $church = config('church.name', 'Wesleyan International Society');

        return "Dear {$submission->first_name}, thank you for registering with {$church}. "
            .'Your information has been received and will be reviewed shortly. '
            .'God bless you!';
    }
}
