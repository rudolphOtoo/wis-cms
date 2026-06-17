# Member Intake Feature — Integration Checklist

This document lists **exact changes** needed to integrate all the new components into your existing codebase.

---

## ✅ Step 1: Environment Configuration

Update `.env`:

```bash
# Generate a random 32-character hex string
WEBHOOK_MEMBER_SUBMISSION_SECRET=$(openssl rand -hex 16)

# Get keys from https://www.google.com/recaptcha/admin
RECAPTCHA_ENABLED=true
RECAPTCHA_SITE_KEY=6Lc_YOUR_SITE_KEY_HERE
RECAPTCHA_SECRET_KEY=6Lc_YOUR_SECRET_KEY_HERE
RECAPTCHA_SCORE_THRESHOLD=0.5

# Queue configuration (local: sync, production: redis)
QUEUE_CONNECTION=database
```

Verify:
```bash
php artisan tinker
>>> config('services.google_form_webhook.secret')
=> "a1b2c3d4e5f6g7h8..."
>>> config('services.google_recaptcha.enabled')
=> true
```

---

## ✅ Step 2: Database & Migration

Verify the migration exists:

```bash
php artisan migrate --list | grep member_submissions
# Should show: "2026_06_10_105405_create_member_submissions_table"
```

If not already migrated:
```bash
php artisan migrate
```

Verify schema:
```bash
php artisan tinker
>>> \DB::table('member_submissions')->first();
```

---

## ✅ Step 3: Update Webhook Controller

**File:** `app/Http/Controllers/Api/MemberSubmissionWebhookController.php`

**Add imports at top:**
```php
use App\Http\Requests\StoreIntakeFormRequest;
use App\Services\CaptchaService;
```

**Update the `store()` method:**

Find this:
```php
public function store(Request $request): JsonResponse
{
    $request->headers->set('Accept', 'application/json');

    $expected = config('services.google_form_webhook.secret');
    $provided = $request->header('X-Webhook-Secret');

    if (empty($expected) || ! is_string($provided) || ! hash_equals($expected, $provided)) {
        Log::warning('Member submission webhook: invalid secret', [
            'ip' => $request->ip(),
            'has_header' => ! empty($provided),
        ]);

        return response()->json(['message' => 'Invalid webhook secret'], 403);
    }

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
```

Replace with:
```php
public function store(StoreIntakeFormRequest $request): JsonResponse
{
    $request->headers->set('Accept', 'application/json');

    $expected = config('services.google_form_webhook.secret');
    $provided = $request->header('X-Webhook-Secret');

    if (empty($expected) || ! is_string($provided) || ! hash_equals($expected, $provided)) {
        Log::warning('Member submission webhook: invalid secret', [
            'ip' => $request->ip(),
            'has_header' => ! empty($provided),
        ]);

        return response()->json(['message' => 'Invalid webhook secret'], 403);
    }

    // CAPTCHA verification
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
        // Fail open if service is down
    }

    $validated = $request->validated();  // Now uses StoreIntakeFormRequest
```

**Keep the rest unchanged** (branch resolution, normalization, persistence).

---

## ✅ Step 4: Update Approval Method

**File:** `app/Http/Controllers/Api/MemberSubmissionController.php`

**Add imports at top:**
```php
use App\Jobs\SendMemberWelcomeSmsJob;
use App\Jobs\SendMemberWelcomeEmailJob;
use App\Jobs\NotifyAdminOfApprovalJob;
```

**Update the `approve()` method:**

Find this:
```php
public function approve(Request $request, string $id): JsonResponse
{
    // ... validation ...

    $member = $submission->promote(
        $request->user(),
        $validated['cell_id'] ?? null,
        $validated['notes'] ?? null,
    );

    activity()->causedBy($request->user())
        ->performedOn($submission)
        ->log("Approved member submission for {$submission->full_name}");

    return response()->json([
        'message' => 'Submission approved and promoted to member.',
        // ... response data ...
    ]);
}
```

Add these lines after `$member = $submission->promote(...)`:

```php
    // Dispatch notification jobs (non-blocking)
    SendMemberWelcomeSmsJob::dispatch($member->id, $member->cell?->name);
    SendMemberWelcomeEmailJob::dispatch($member->id);
    NotifyAdminOfApprovalJob::dispatch($submission->id, $member->id);
```

**Full updated method:**
```php
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

    $member = $submission->promote(
        $request->user(),
        $validated['cell_id'] ?? null,
        $validated['notes'] ?? null,
    );

    // ✨ NEW: Dispatch notification jobs
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
                'name' => trim("{$member->first_name} {$member->last_name}"),
                'phone' => $member->phone,
                'cell_id' => $member->cell_id,
            ],
        ],
    ]);
}
```

---

## ✅ Step 5: Queue Configuration

### Local Development

In `.env`:
```bash
QUEUE_CONNECTION=sync
```

This executes jobs immediately (no async).

### Production

In `.env`:
```bash
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
```

Create Supervisor config:

```ini
# /etc/supervisor/conf.d/wis-cms-queue-worker.conf
[program:wis-cms-queue-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/wis-cms/artisan queue:work redis --queue=default --tries=3 --sleep=3 --memory=128
autostart=true
autorestart=true
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/wis-cms-queue-worker.log
user=www-data

[group:wis-cms]
programs=wis-cms-queue-worker
```

Start workers:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start wis-cms:*
```

---

## ✅ Step 6: Testing

### Manual Test

```bash
# 1. Start queue worker (in separate terminal)
php artisan queue:work --verbose

# 2. In main terminal, test the webhook
curl -X POST http://localhost:8000/api/webhooks/member-submission \
  -H "X-Webhook-Secret: $(php artisan tinker --execute='echo config("services.google_form_webhook.secret");')" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Kofi",
    "last_name": "Mensah",
    "phone": "0244123456",
    "email": "kofi@example.com",
    "gender": "male",
    "date_of_birth": "1990-05-25",
    "captcha_token": "test-token"
  }'

# Expected response (201):
# {"data":{"id":"550e8400-...","submitted_at":"2026-06-17T...","status":"pending"}}

# 3. Check that it was stored
php artisan tinker
>>> \App\Models\MemberSubmission::latest()->first();
# Should show your test submission

# 4. Approve it (from admin)
# Login to admin, go to submissions, click approve
# Watch the queue worker terminal — should see SMS/email jobs execute

# 5. Check logs
tail -f storage/logs/laravel.log | grep "Welcome"
```

### Unit Tests

```bash
php artisan test tests/Unit/Http/Requests/StoreIntakeFormRequestTest.php
php artisan test tests/Unit/Services/CaptchaServiceTest.php
```

### Feature Tests

```bash
php artisan test tests/Feature/MemberIntakeWorkflowTest.php
```

---

## ✅ Step 7: Frontend Integration

In your React intake form component:

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

    if (response.ok) {
      // Show success
      setSuccess('Thank you for joining!')
    } else {
      const data = await response.json()
      // Show error
      setError(data.message || 'Submission failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      {/* Your form fields */}
    </form>
  )
}
```

---

## ✅ Step 8: Verify Routes

In `routes/api.php`, verify the webhook route:

```php
// PUBLIC WEBHOOKS (no Sanctum auth)
Route::post('/webhooks/member-submission',
    [MemberSubmissionWebhookController::class, 'store']
)->middleware('throttle:60,1');
```

Verify admin routes:

```php
Route::middleware(['auth:sanctum', 'permission:view member submissions'])->group(function () {
    Route::get('submissions', [MemberSubmissionController::class, 'index']);
    Route::get('submissions/{id}', [MemberSubmissionController::class, 'show']);
});

Route::middleware(['auth:sanctum', 'permission:manage member submissions'])->group(function () {
    Route::post('submissions/{id}/approve', [MemberSubmissionController::class, 'approve']);
    Route::post('submissions/{id}/reject', [MemberSubmissionController::class, 'reject']);
});
```

---

## ✅ Step 9: Deployment Checklist

Before deploying to production:

- [ ] `.env` configured with reCAPTCHA keys
- [ ] `WEBHOOK_MEMBER_SUBMISSION_SECRET` set to random value
- [ ] `QUEUE_CONNECTION=redis` (or your queue driver)
- [ ] Redis/database configured for jobs
- [ ] Supervisor queue workers configured & running
- [ ] Email service configured (SMTP or mNotify)
- [ ] SMS service credentials verified
- [ ] Migration applied (`php artisan migrate`)
- [ ] Tests passing (`php artisan test`)
- [ ] Logs configured for rotation
- [ ] Monitoring set up (queue depth, failed jobs)
- [ ] Backup strategy for data

---

## ✅ Step 10: Post-Deployment Verification

```bash
# Check webhook secret configured
php artisan tinker
>>> config('services.google_form_webhook.secret')

# Test queue workers
php artisan queue:work --verbose

# Test CAPTCHA
# (Make actual submission and check logs)

# Monitor queue
SELECT COUNT(*) FROM jobs;
php artisan queue:failed

# Check logs
tail -f storage/logs/laravel.log
```

---

## Rollback Plan

If something goes wrong:

```bash
# Stop queue workers
sudo supervisorctl stop wis-cms:*

# Revert webhook controller changes
git checkout app/Http/Controllers/Api/MemberSubmissionWebhookController.php

# Restart with old code
sudo supervisorctl start wis-cms:*

# Submissions in member_submissions table remain; no data loss
```

---

## Common Issues & Solutions

| Issue | Solution |
|-------|----------|
| CAPTCHA always fails | Check reCAPTCHA console; verify keys in `.env` |
| Jobs not executing | Check `QUEUE_CONNECTION`; ensure workers running |
| "Invalid webhook secret" | Regenerate secret: `openssl rand -hex 16` |
| Queue backed up | Add more workers: increase `numprocs` in supervisor config |
| Emails not sending | Check mail config; test with `Mail::raw()->send()` |
| SMS not sending | Verify mNotify API key; check phone format |

---

## Files Changed Summary

| File | Type | Changes |
|------|------|---------|
| `.env` | Config | Added RECAPTCHA_*, WEBHOOK_SECRET |
| `MemberSubmissionWebhookController.php` | Code | Added CaptchaService, uses FormRequest |
| `MemberSubmissionController.php` | Code | Added job dispatches on approval |
| `config/services.php` | Config | Added google_recaptcha section |
| Migration `2026_06_10_105405` | Schema | Already applied (verify) |

## Files Created (New)

| File | Purpose |
|------|---------|
| `StoreIntakeFormRequest.php` | Form validation |
| `CaptchaService.php` | reCAPTCHA v3 integration |
| `SendMemberWelcomeSmsJob.php` | Welcome SMS job |
| `SendMemberWelcomeEmailJob.php` | Welcome email job |
| `NotifyAdminOfApprovalJob.php` | Admin notification job |
| `MemberWelcomeEmail.php` | Email template |
| `emails/member-welcome.blade.php` | Email HTML |

---

**You're ready to go!** Follow steps 1-10 in order.

Questions? Check:
1. `MEMBER_INTAKE_ARCHITECTURE.md` — Design & security
2. `MEMBER_INTAKE_SETUP.md` — Step-by-step guide
3. `MEMBER_INTAKE_FEATURE_SUMMARY.md` — Overview
