<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendIntakeThankYouJob;
use App\Models\Branch;
use App\Models\MemberSubmission;
use App\Support\PhoneNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook endpoint for the self-service member-registration Google Form.
 *
 * AUTH MODEL
 *   - No Sanctum (caller is Google Apps Script, not a logged-in user).
 *   - Shared secret validated via X-Webhook-Secret header (constant-time
 *     comparison to prevent timing side-channels).
 *   - Endpoint is rate-limited via the 'intake-webhook' named limiter
 *     (5 requests/IP/10 min + 3 requests/phone/day).
 *
 * TRUST MODEL
 *   - All data lands in member_submissions (status = 'pending').
 *   - Nothing is auto-promoted to the members table.
 *   - Admin reviews and approves each submission explicitly.
 *
 * IDEMPOTENCY
 *   - Callers may supply an X-Idempotency-Key header (≤ 64 chars).
 *   - If the header is absent, a deterministic SHA-256 key is derived
 *     from phone + first_name + source_ip.
 *   - A duplicate delivery with the same key returns the original
 *     record's data with HTTP 200 (not 201) — no duplicate row created.
 *   - A genuine race condition (two simultaneous requests with identical
 *     keys) is caught via UniqueConstraintViolationException and handled
 *     by fetching the winning row.
 */
class MemberSubmissionWebhookController extends Controller
{
    /**
     * POST /api/webhooks/member-submission
     *
     * Expected JSON body:
     * {
     *   "first_name":     "Kofi",
     *   "last_name":      "Mensah",
     *   "phone":          "0244123456",
     *   "email":          "kofi@example.com",      // optional
     *   "gender":         "male",
     *   "date_of_birth":  "1990-05-25",            // optional, YYYY-MM-DD
     *   "address":        "East Legon, Accra",     // optional
     *   "occupation":     "Teacher",               // optional
     *   "marital_status": "married",               // optional
     *   "cell_name":      "Bethel Fellowship"      // optional, free text
     * }
     *
     * Expected headers:
     *   X-Webhook-Secret:   <shared secret from .env>
     *   X-Idempotency-Key:  <caller-generated unique token>  // optional
     *
     * Responses:
     *   201  { "data": { "id": "...", "submitted_at": "...", "status": "pending" } }
     *   200  (same shape) — duplicate delivery of an already-received submission
     *   403  { "message": "Invalid webhook secret" }
     *   422  { "message": "Validation failed", "errors": { ... } }
     *   500  { "message": "Could not process submission" }
     */
    public function store(Request $request): JsonResponse
    {
        // Force JSON responses regardless of the client's Accept header.
        // Google Apps Script sends Accept: application/json by default,
        // but other integrations (curl, test runners) may not. Without
        // this, validation failures would 302-redirect instead of 422 JSON.
        $request->headers->set('Accept', 'application/json');

        // ── Step 1: Authenticate via shared secret ────────────────────────────
        // Constant-time comparison (hash_equals) prevents timing attacks.
        // A missing or empty configured secret is treated as a configuration
        // error — the endpoint rejects all requests until fixed.
        $expected = config('services.google_form_webhook.secret');
        $provided = $request->header('X-Webhook-Secret');

        if (
            empty($expected)
            || ! is_string($provided)
            || ! hash_equals((string) $expected, $provided)
        ) {
            Log::warning('Member submission webhook: invalid or missing secret', [
                'ip' => $request->ip(),
                'has_header' => ! empty($provided),
            ]);

            return response()->json(['message' => 'Invalid webhook secret'], 403);
        }

        // ── Step 2: Validate payload ──────────────────────────────────────────
        // Validation is deliberately loose on optional fields — members may
        // leave many fields blank in the Google Form. Core identity fields
        // (names, phone) are required and constrained.
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'min:2', 'max:100'],
            'last_name' => ['required', 'string', 'min:2', 'max:100'],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'gender' => ['required', 'string', 'in:male,female,other'],
            'date_of_birth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'address' => ['nullable', 'string', 'max:500'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'marital_status' => ['nullable', 'string', 'in:single,married,widowed,divorced,separated'],
            'cell_name' => ['nullable', 'string', 'max:100'],
        ]);

        // ── Step 3: Normalize phone ───────────────────────────────────────────
        // All phones are stored in canonical local format (0XXXXXXXXX).
        // This ensures downstream duplicate detection and SMS dispatch
        // work correctly regardless of how the submitter formatted the number.
        $phone = PhoneNormalizer::normalize($validated['phone']);

        // ── Step 4: Resolve branch ───────────────────────────────────────────
        // Single-branch system for now. When going multi-branch, the form
        // would include a branch_code field and this becomes a lookup.
        $branch = Branch::first();

        if ($branch === null) {
            Log::error('Member submission webhook: no branch configured in system');

            return response()->json(['message' => 'System not configured — no branch found.'], 500);
        }

        // ── Step 5: Resolve idempotency key ──────────────────────────────────
        // Prefer caller-supplied key (Google Apps Script can pass the Google
        // Form response row ID as a stable, unique token). Fall back to a
        // deterministic hash derived from the core identity fields + IP.
        // Both approaches guarantee that a retry of the exact same submission
        // does not produce a second row in the queue.
        $rawKey = $request->header('X-Idempotency-Key', '');
        $idempotencyKey = $this->resolveIdempotencyKey(
            rawKey: is_string($rawKey) ? trim($rawKey) : '',
            phone: $phone,
            firstName: trim($validated['first_name']),
            ip: (string) $request->ip(),
        );

        // ── Step 6: Deduplication check ───────────────────────────────────────
        // Look up an existing submission with this idempotency key before
        // attempting an insert. If found, return the existing record silently
        // (HTTP 200). This handles the common case: Apps Script retries after
        // a perceived timeout even though the server processed the request.
        $existing = MemberSubmission::where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            Log::info('Member submission webhook: duplicate delivery suppressed', [
                'submission_id' => $existing->id,
                'idempotency_key' => $idempotencyKey,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'data' => [
                    'id' => $existing->id,
                    'submitted_at' => $existing->submitted_at,
                    'status' => $existing->status,
                ],
            ], 200);
        }

        // ── Step 7: Sanitize raw payload ──────────────────────────────────────
        // Store the complete inbound payload for forensic / audit purposes,
        // but strip any keys that could represent credentials. The webhook
        // secret lives in the header (not the body), so this is belt-and-
        // suspenders for future-proofing against body-based auth schemes.
        $sensitiveKeys = ['secret', 'password', 'token', 'api_key', 'key', 'authorization'];
        $safePayload = collect($request->all())
            ->except($sensitiveKeys)
            ->all();

        // ── Step 8: Persist ───────────────────────────────────────────────────
        // Wrapped in try-catch for two distinct failure modes:
        //  a) UniqueConstraintViolationException: a concurrent request with
        //     the same idempotency key won the race — fetch and return theirs.
        //  b) Any other Throwable: genuine storage failure — log and 500.
        try {
            $submission = MemberSubmission::create([
                'branch_id' => $branch->id,
                'first_name' => trim($validated['first_name']),
                'last_name' => trim($validated['last_name']),
                'phone' => $phone,
                'email' => $validated['email'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'address' => $validated['address'] ?? null,
                'occupation' => $validated['occupation'] ?? null,
                'marital_status' => $validated['marital_status'] ?? null,
                'cell_name' => $validated['cell_name'] ?? null,
                'submitted_at' => now(),
                'source_ip' => $request->ip(),
                'raw_payload' => $safePayload,
                'idempotency_key' => $idempotencyKey,
                'source' => MemberSubmission::SOURCE_GOOGLE_FORM,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Race condition: another request with the same idempotency key
            // committed between our dedup check and our insert attempt.
            // Fetch the winning row and return it as if we found it above.
            $winner = MemberSubmission::where('idempotency_key', $idempotencyKey)->firstOrFail();

            Log::info('Member submission webhook: race-condition duplicate suppressed', [
                'submission_id' => $winner->id,
                'idempotency_key' => $idempotencyKey,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'data' => [
                    'id' => $winner->id,
                    'submitted_at' => $winner->submitted_at,
                    'status' => $winner->status,
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Member submission webhook: storage failed', [
                'error' => $e->getMessage(),
                'phone' => $phone,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Could not process submission.'], 500);
        }

        Log::info('Member submission received', [
            'submission_id' => $submission->id,
            'phone' => $phone,
            'source' => $submission->source,
            'idempotency_key' => $idempotencyKey,
        ]);

        // ── Step 9: Dispatch thank-you SMS (non-blocking) ─────────────────────
        // The job runs on the queue worker — the webhook response returns
        // immediately to Google Apps Script without waiting for SMS delivery.
        // A 5-second delay gives the DB commit time to propagate before the
        // job reads the row.
        SendIntakeThankYouJob::dispatch($submission->id)
            ->delay(now()->addSeconds(5));

        return response()->json([
            'data' => [
                'id' => $submission->id,
                'submitted_at' => $submission->submitted_at,
                'status' => $submission->status,
            ],
        ], 201);
    }

    /**
     * Resolve the idempotency key for this request.
     *
     * Preference order:
     *   1. Caller-supplied X-Idempotency-Key header (any length, trimmed).
     *   2. SHA-256 hash of {phone}|{firstName}|{ip} — deterministic fallback
     *      that produces the same key for retry attempts from the same origin.
     *
     * Both branches pass through SHA-256, giving a consistent 64 hex-char
     * output with no length-dependent padding logic and no collision surface.
     * Namespaced prefixes ('caller:' vs 'fallback:') guarantee that a
     * caller-supplied key whose raw value happens to match a fallback-derived
     * input string still produces a distinct stored key.
     */
    private function resolveIdempotencyKey(
        string $rawKey,
        string $phone,
        string $firstName,
        string $ip,
    ): string {
        if ($rawKey !== '') {
            return hash('sha256', 'caller:'.$rawKey);
        }

        return hash('sha256', "fallback:{$phone}|{$firstName}|{$ip}");
    }
}
