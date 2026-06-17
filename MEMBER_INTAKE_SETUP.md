# Member Intake Form — Implementation Guide

This guide walks you through implementing the complete Member Intake Form feature with staging, security, and background jobs.

## Quick Setup (5 steps)

### 1. Update Environment Variables

```bash
# .env
WEBHOOK_MEMBER_SUBMISSION_SECRET=$(openssl rand -hex 16)
RECAPTCHA_ENABLED=true
RECAPTCHA_SITE_KEY=your_site_key_from_google_console
RECAPTCHA_SECRET_KEY=your_secret_key_from_google_console
RECAPTCHA_SCORE_THRESHOLD=0.5
```

Generate a random secret:
```bash
php artisan tinker
>>> bin2hex(random_bytes(16))
```

### 2. Verify Migration Is Applied

```bash
php artisan migrate
# Should show: Migrated: 2026_06_10_105405_create_member_submissions_table
```

### 3. Update the Webhook Controller

Replace `app/Http/Controllers/Api/MemberSubmissionWebhookController.php` with the enhanced version:

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreIntakeFormRequest;
use App\Models\Branch;
use App\Models\MemberSubmission;
use App\Services\CaptchaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MemberSubmissionWebhookController extends Controller
{
    public function store(StoreIntakeFormRequest $request): JsonResponse
    {
        // Force JSON responses
        $request->headers->set('Accept', 'application/json');

        // Step 1 — Validate shared secret
        $expected = config('services.google_form_webhook.secret');
        $provided = $request->header('X-Webhook-Secret');

        if (empty($expected) || ! is_string($provided) || ! hash_equals($expected, $provided)) {
            Log::warning('Member submission webhook: invalid secret', [
                'ip' => $request->ip(),
                'has_header' => ! empty($provided),
            ]);

            return response()->json(['message' => 'Invalid webhook secret'], 403);
        }

        // Step 2 — CAPTCHA verification (now integrated via FormRequest)
        // The StoreIntakeFormRequest validates captcha_token
        // We verify it here
        try {
            if (! CaptchaService::verify(
                $request->input('captcha_token'),
                $request->ip()
            )) {
                return response()->json([
                    'message' => 'CAPTCHA verification failed. Please try again.',
                ], 422);
            }
        } catch (\Throwable $e) {
            Log::warning('CAPTCHA verification error', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);
            // Fail open: allow submission if CAPTCHA service is down
        }

        // Step 3 — Resolve branch
        $branch = Branch::first();
        if (! $branch) {
            Log::error('Member submission webhook: no branch in system');

            return response()->json(['message' => 'No branch configured'], 500);
        }

        // Step 4 — Validated data (already normalized by FormRequest)
        $validated = $request->validated();

        // Step 5 — Persist
        try {
            $submission = MemberSubmission::create([
                'branch_id' => $branch->id,
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
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
                'phone' => $validated['phone'],
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
                'phone' => $validated['phone'] ?? 'unknown',
            ]);

            return response()->json(['message' => 'Could not process submission'], 500);
        }
    }
}
```

### 4. Update Approval to Dispatch Jobs

Update the `approve()` method in `MemberSubmissionController.php`:

```php
<?php

use App\Jobs\SendMemberWelcomeSmsJob;
use App\Jobs\SendMemberWelcomeEmailJob;
use App\Jobs\NotifyAdminOfApprovalJob;

public function approve(Request $request, string $id): JsonResponse
{
    $validated = $request->validate([
        'cell_id' => ['nullable', 'uuid', 'exists:cells,id'],
        'notes' => ['nullable', 'string', 'max:500'],
    ]);

    $submission = MemberSubmission::where('branch_id', $request->user()->branch_id)
        ->where('id', $id)
        ->firstOrFail();

    if ($submission->status !== MemberSubmission::STATUS_PENDING) {
        return response()->json([
            'message' => "Submission already {$submission->status}.",
        ], 422);
    }

    // Promote to Member
    $member = $submission->promote(
        $request->user(),
        $validated['cell_id'] ?? null,
        $validated['notes'] ?? null,
    );

    // ✨ NEW: Dispatch notification jobs (non-blocking)
    SendMemberWelcomeSmsJob::dispatch($member->id, $member->cell?->name);
    SendMemberWelcomeEmailJob::dispatch($member->id);
    NotifyAdminOfApprovalJob::dispatch($submission->id, $member->id);

    activity()->causedBy($request->user())
        ->performedOn($submission)
        ->log("Approved member submission for {$submission->full_name}");

    return response()->json([
        'message' => 'Submission approved and promoted to member.',
        'data' => [
            'submission_id' => $submission->id,
            'member' => [
                'id' => $member->id,
                'name' => $member->full_name,
                'phone' => $member->phone,
                'cell_id' => $member->cell_id,
            ],
        ],
    ]);
}
```

### 5. Start Queue Workers

```bash
# Local development (synchronous queue)
# Update .env: QUEUE_CONNECTION=sync

# Production (Redis or Database queue)
php artisan queue:work redis \
  --queue=default \
  --tries=3 \
  --sleep=3 \
  --memory=128

# Or via Supervisor
[program:wis-cms-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/wis-cms/artisan queue:work redis --queue=default --tries=3
autostart=true
autorestart=true
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/wis-cms-worker.log
```

---

## Configuration Details

### reCAPTCHA Setup

1. Go to https://www.google.com/recaptcha/admin
2. Click "Create" and register a new site
3. Choose "reCAPTCHA v3"
4. Add domains: `localhost`, `wis-cms.local`, `your-production-domain.com`
5. Copy **Site Key** and **Secret Key** to `.env`

### Frontend Integration

In your React intake form:

```jsx
import { useEffect } from 'react'

export default function MemberIntakeForm() {
  useEffect(() => {
    // Load reCAPTCHA script
    const script = document.createElement('script')
    script.src = 'https://www.google.com/recaptcha/api.js'
    document.head.appendChild(script)
  }, [])

  const handleSubmit = async (e) => {
    e.preventDefault()

    // Get reCAPTCHA token
    const token = await window.grecaptcha.execute(
      process.env.REACT_APP_RECAPTCHA_SITE_KEY,
      { action: 'submit_member_form' }
    )

    // Submit form with token
    const response = await fetch('/api/webhooks/member-submission', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Webhook-Secret': process.env.REACT_APP_WEBHOOK_SECRET,
      },
      body: JSON.stringify({
        first_name: formData.firstName,
        last_name: formData.lastName,
        phone: formData.phone,
        email: formData.email,
        gender: formData.gender,
        date_of_birth: formData.dob,
        address: formData.address,
        occupation: formData.occupation,
        marital_status: formData.maritalStatus,
        cell_name: formData.cellName,
        captcha_token: token,
      })
    })

    const data = await response.json()
    // Handle response...
  }

  return (
    <form onSubmit={handleSubmit}>
      {/* Your form fields */}
    </form>
  )
}
```

---

## Testing

### Manual Testing

```bash
# Test with curl
curl -X POST http://localhost:8000/api/webhooks/member-submission \
  -H "X-Webhook-Secret: your-secret-here" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Kofi",
    "last_name": "Mensah",
    "phone": "0244123456",
    "email": "kofi@example.com",
    "gender": "male",
    "date_of_birth": "1990-05-25",
    "address": "East Legon",
    "occupation": "Teacher",
    "marital_status": "married",
    "cell_name": "Bethel Fellowship",
    "captcha_token": "test-token"
  }'

# Response (201 Created)
{
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440001",
    "submitted_at": "2026-06-17T14:32:00Z",
    "status": "pending"
  }
}
```

### Unit Tests

```php
<?php
// tests/Unit/Services/CaptchaServiceTest.php

public function test_captcha_verify_with_disabled_service()
{
    config(['services.google_recaptcha.enabled' => false]);
    
    $result = CaptchaService::verify('any-token');
    $this->assertTrue($result);
}

public function test_form_request_normalizes_phone()
{
    $request = new StoreIntakeFormRequest();
    $this->assertEquals('0244123456', $request->normalizePhone('+233244123456'));
    $this->assertEquals('0244123456', $request->normalizePhone('233244123456'));
    $this->assertEquals('0244123456', $request->normalizePhone('0244123456'));
}
```

### Feature Tests

```php
<?php
// tests/Feature/MemberIntakeWorkflowTest.php

public function test_complete_intake_workflow()
{
    Queue::fake();

    // 1. Submit form
    $response = $this->postJson('/api/webhooks/member-submission', [
        'first_name' => 'Kofi',
        'last_name' => 'Mensah',
        'phone' => '+233244123456',
        'gender' => 'male',
        'captcha_token' => 'valid-token',
    ], [
        'X-Webhook-Secret' => config('services.google_form_webhook.secret'),
    ]);

    $response->assertStatus(201);
    $submission = MemberSubmission::first();
    $this->assertEquals('0244123456', $submission->phone);

    // 2. Admin approves
    $admin = $this->createAdminUser();
    $response = $this->actingAs($admin)->postJson(
        "/api/submissions/{$submission->id}/approve",
        []
    );

    $response->assertStatus(200);

    // 3. Jobs dispatched
    Queue::assertPushed(SendMemberWelcomeSmsJob::class);
    Queue::assertPushed(SendMemberWelcomeEmailJob::class);

    // 4. Verify member created
    $member = Member::where('phone', '0244123456')->first();
    $this->assertNotNull($member);
}
```

---

## Monitoring & Support

### Check Queue Status

```bash
# See pending jobs
php artisan queue:failed
php artisan queue:retry all

# Monitor job execution
php artisan queue:work --verbose

# Check database jobs
SELECT * FROM jobs WHERE attempts < 3;
```

### Logs to Monitor

```bash
# Watch for intake submissions
tail -f storage/logs/laravel.log | grep "member submission"

# Watch for SMS/email failures
tail -f storage/logs/laravel.log | grep "failed\|error"

# Verify jobs processing
tail -f storage/logs/laravel.log | grep "SendMemberWelcome"
```

### Common Issues

| Issue | Solution |
|-------|----------|
| CAPTCHA always fails | Verify site/secret keys in `.env` |
| Jobs not processing | Check `QUEUE_CONNECTION` in `.env`, ensure workers running |
| SMS not sent | Verify mNotify credentials, check phone format |
| Rate limit errors | Reduce throttle rate in routes or check IP masking (proxies) |

---

## Production Checklist

- [ ] `WEBHOOK_MEMBER_SUBMISSION_SECRET` set to random value
- [ ] `RECAPTCHA_ENABLED=true` and keys configured
- [ ] Queue workers running (4+ processes)
- [ ] Email service configured (SMTP or mNotify)
- [ ] SMS service credentials verified
- [ ] Database indexes applied
- [ ] Monitoring/alerting configured
- [ ] Logs rotated daily
- [ ] Backup plan for failed jobs
- [ ] Test intake workflow end-to-end

---

## Support Resources

- [Architecture Blueprint](MEMBER_INTAKE_ARCHITECTURE.md)
- [Google reCAPTCHA Docs](https://developers.google.com/recaptcha/docs/v3)
- [Laravel Queue Documentation](https://laravel.com/docs/11.x/queues)
- [Laravel Mail Documentation](https://laravel.com/docs/11.x/mail)

