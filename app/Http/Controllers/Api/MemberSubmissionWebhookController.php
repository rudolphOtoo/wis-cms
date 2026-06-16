<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\MemberSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook endpoint for the self-service member-registration Google Form.
 *
 * AUTH MODEL:
 *   - No Sanctum (the caller is Google Apps Script, not a logged-in user)
 *   - Shared secret in X-Webhook-Secret header
 *   - Endpoint is rate-limited at the route level
 *   - Unauthorized requests get 403 immediately
 *
 * TRUST MODEL:
 *   - Data lands in member_submissions table with status='pending'
 *   - Never auto-promotes to the members table
 *   - Admin reviews and approves explicitly via /admin/submissions
 */
class MemberSubmissionWebhookController extends Controller
{
    /**
     * POST /api/webhooks/member-submission
     *
     * Expected body (JSON):
     *   {
     *     "first_name":  "Kofi",
     *     "last_name":   "Mensah",
     *     "phone":       "0244123456",
     *     "email":       "kofi@example.com",      // optional
     *     "gender":      "male",                  // optional
     *     "date_of_birth": "1990-05-25",          // optional, YYYY-MM-DD
     *     "address":     "East Legon, Accra",     // optional
     *     "occupation":  "Teacher",               // optional
     *     "marital_status": "married",            // optional
     *     "cell_name":   "Bethel Fellowship"      // optional, free text
     *   }
     *
     * Expected headers:
     *   X-Webhook-Secret: <shared secret from .env>
     *
     * Responses:
     *   201 { "data": { "id": "...", "submitted_at": "..." } }
     *   403 { "message": "Invalid webhook secret" }
     *   422 { "message": "Validation failed", "errors": {...} }
     *   500 { "message": "Could not process submission" }
     */
    public function store(Request $request): JsonResponse
    {
        // Force JSON responses regardless of the client's Accept header.
        // Google Apps Script sends 'Accept: application/json' by default,
        // but other clients (curl without explicit headers, custom
        // integrations) may not. Without this, validation failures would
        // 302-redirect instead of returning 422 JSON.
        $request->headers->set('Accept', 'application/json');

        // Step 1 — Validate shared secret. Constant-time compare to avoid
        // timing side-channels (probably overkill for our threat model
        // but it's free and correct).
        $expected = config('services.google_form_webhook.secret');
        $provided = $request->header('X-Webhook-Secret');

        if (empty($expected) || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            Log::warning('Member submission webhook: invalid secret', [
                'ip' => $request->ip(),
                'has_header' => ! empty($provided),
            ]);

            return response()->json(['message' => 'Invalid webhook secret'], 403);
        }

        // Step 2 — Validate payload shape. Keep validation loose-but-real:
        // members may not fill every field, but core identity ones we require.
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'gender' => ['required', 'string', 'in:male,female,other'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'address' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:100'],
            'marital_status' => ['nullable', 'string', 'in:single,married,widowed,divorced,separated'],
            'cell_name' => ['nullable', 'string', 'max:100'],
        ]);

        // Step 3 — Resolve branch. For now, single-branch app: the form
        // submissions land in the first (only) branch. When we go
        // multi-branch, we'd add a branch_code field to the form.
        $branch = Branch::first();
        if (! $branch) {
            Log::error('Member submission webhook: no branch in system');

            return response()->json(['message' => 'No branch configured'], 500);
        }

        // Step 4 — Normalize phone (strip whitespace, leading +233 → 0)
        $phone = $this->normalizePhone($validated['phone']);

        // Step 5 — Persist
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
                'raw_payload' => $request->all(),
            ]);

            Log::info('Member submission received', [
                'submission_id' => $submission->id,
                'phone' => $phone,
            ]);

            return response()->json([
                'data' => [
                    'id' => $submission->id,
                    'submitted_at' => $submission->submitted_at,
                    'status' => $submission->status,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Member submission webhook: storage failed', [
                'error' => $e->getMessage(),
                'phone' => $phone,
            ]);

            return response()->json(['message' => 'Could not process submission'], 500);
        }
    }

    /**
     * Normalize a Ghanaian phone number into local 0XXXXXXXXX format.
     * Handles common variants: +233 prefix, spaces, leading zero missing.
     */
    protected function normalizePhone(string $phone): string
    {
        // Strip all whitespace + dashes
        $phone = preg_replace('/\s|-/', '', $phone);

        // +233xxxxxxxxx → 0xxxxxxxxx
        if (str_starts_with($phone, '+233')) {
            return '0'.substr($phone, 4);
        }

        // 233xxxxxxxxx → 0xxxxxxxxx
        if (str_starts_with($phone, '233') && strlen($phone) === 12) {
            return '0'.substr($phone, 3);
        }

        return $phone;
    }
}
