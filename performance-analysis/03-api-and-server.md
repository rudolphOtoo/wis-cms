# API & Server Performance

---

## Middleware chain

| Layer | File | Cost |
|-------|------|------|
| Bootstrap | `bootstrap/app.php` | `statefulApi()` (Sanctum cookies for SPA domains) |
| Auth | `auth:sanctum` on ~all routes after login | Token lookup + user load |
| Password gate | `EnsurePasswordChanged` | Single field check on user |
| Permissions | `permission:*` per route group | Spatie check (registry cached 24h) |

**File:** `routes/api.php` — public routes: `login`, `forgot-password`, `reset-password` only.

**Implication:** Every protected request pays Sanctum + password middleware; many routes add permission middleware. Acceptable for typical CMS traffic; not optimized for high-QPS public APIs.

---

## Auth overhead

| Item | Location | Note |
|------|----------|------|
| Token expiration | `config/sanctum.php` — `null` | Tokens don't expire by config |
| `/auth/me` | `UserResource` | `getRoleNames()` + `getAllPermissions()` on each call |
| Login | `AuthController` | Deletes all tokens, creates one — extra writes (login only) |
| Permission cache | `config/permission.php` | 24h registry cache; per-request role checks remain |

---

## P0 — Sync blocking on broadcasts

**Files:**
- `app/Http/Controllers/Api/MessageController.php` (`send`)
- `app/Jobs/SendBroadcastMessageJob.php`

When `QUEUE_CONNECTION=sync` (common in `.env.example` dev defaults), each `SendBroadcastMessageJob::dispatch()` runs **inline** in the HTTP request: Mail + mNotify HTTP per recipient.

**Impact:** Sending to 200 members can exceed PHP/nginx timeout and block the UI.

**Fix (ops):** `QUEUE_CONNECTION=database` or `redis` + Supervisor `queue:work`.  
**Fix (code):** Document requirement; optionally reject large sends if sync detected.

---

## P1 — Heavy synchronous endpoints

### Dashboard (`GET /api/dashboard`)

**File:** `DashboardController::index` lines 17–120+

- 15+ separate queries (counts, sums, charts)
- Finance chart: 12 queries in PHP loop (lines 93–109)
- Attendance chart: 8 sessions with `records` + accessor counts (N+1 on counts)

**Leader variant:** `leaderDashboard` — loads all members per department/cell; N+1 session queries per unit.

**Mitigation:** Short TTL cache (`Cache::remember("dashboard:{$branchId}", 60, ...)`), grouped SQL, fix attendance counts.

---

### Attendance session detail (`GET /api/attendance/sessions/{id}`)

**File:** `AttendanceController::showSession` lines 184–194

Branch-wide adult service:

```php
$people = Member::where('branch_id', $branchId)
    ->where('status', 'active')
    ->orderBy('first_name')
    ->get()
```

Plus in-memory join against `$session->records` per member (lines 188–193).

**Impact:** O(members) memory and JSON payload; frontend renders every row (`TakeAttendance.jsx`).

**Mitigation:** Search/pagination API; only send delta on mark; virtualized list.

---

### Mark attendance (`POST /api/attendance/sessions/{id}/mark`)

**File:** lines 218–238 — `updateOrCreate` per record in loop.

**Impact:** N DB round-trips for full roll call.

---

### Message send (`POST /api/messages/send`)

**File:** `MessageController::send`

- `resolveRecipients(...)->get()` — all matching members in memory
- Loop: `MessageRecipient::create` + job dispatch per member
- Runs inside DB transaction

**Related:** `CellController::message`, `DepartmentController::message`

---

### Message detail (`GET /api/messages/{id}`)

**File:** `MessageController::show` — `with(['recipients.member'])` loads all recipients.

**Frontend:** `MessageDetail.jsx` maps entire list — no pagination.

---

### PDF generation (inline)

| Endpoint | Controller | Work |
|----------|------------|------|
| `GET members/{id}/giving-statement` | `MemberController` | DomPDF |
| `GET finance/reports/ledger` | `FinanceController` | Load all txs in range + DomPDF |

**Impact:** CPU and memory on request thread.

**Mitigation:** Queue PDF job + poll/download URL; or stream generation with memory limits.

---

### Stats endpoints (query fan-out)

| Route | Controller method |
|-------|-------------------|
| `GET /api/members/stats` | 7× COUNT |
| `GET /api/finance/stats` | 6-month loop × 2 SUM |
| `GET /api/attendance/stats` | Multiple loads + unbounded `get()` for insights |

---

## Positive patterns

| Pattern | Location |
|---------|----------|
| Streamed CSV export | `MemberController::export`, `FinanceController::export` — `StreamedResponse` + `lazy()` |
| Queued SMS/email | `SendBroadcastMessageJob`, `DispatchAttendanceFollowUpJob` |
| Scheduled work off-request | `routes/console.php` — birthdays, follow-ups, audit |
| Pessimistic locking | `Member` create — `lockForUpdate()` for member numbers |

---

## HTTP caching

**Finding:** No `Cache-Control`, `ETag`, or `Last-Modified` on JSON API responses.

Repeat dashboard loads always hit the database.

**Optional:** `Cache-Control: private, max-age=60` on stats; ETag on static config endpoints.

---

## Rate limiting

| Route | Limit |
|-------|-------|
| `POST /api/auth/login` | 5 attempts / 60s per email+IP (`AuthController`) |
| All other routes | **None** |

**Gaps:** `forgot-password`, `reset-password` unthrottled; no per-user limits on exports or message send.

---

## External services

### mNotify SMS

**File:** `app/Services/MnotifySmsService.php` lines 44–50

```php
$response = Http::asJson()->post($endpoint, [...]);
```

- No `timeout()`, `connectTimeout()`, or `retry()`
- Laravel default ~30s can block queue workers

**Fix:** `Http::timeout(10)->retry(2, 100)->post(...)`

### Mail

`config/mail.php` — SMTP `timeout` => `null`. Password reset runs inline in `forgotPassword`.

---

## Activity log

Widespread `activity()->causedBy($user)->...->log()` on mutations (Auth, Member, Finance, Attendance).

**Impact:** Synchronous INSERT per mutation. Acceptable at CMS scale; batch or queue if audit volume grows.

---

## Jobs configuration

| Setting | Default (`.env.example`) |
|---------|--------------------------|
| `QUEUE_CONNECTION` | `database` |
| `CACHE_STORE` | `database` |

`SendBroadcastMessageJob`: no `$timeout`, `$backoff`.  
`DispatchAttendanceFollowUpJob`: `$tries = 1`.

**Production requirement:** Running workers documented in `DEPLOYMENT.md`.

---

## File I/O

No heavy upload paths in API controllers. Exports write to `php://output` stream — good.

`AuditFkRelationships` command reads model files — CLI only.
