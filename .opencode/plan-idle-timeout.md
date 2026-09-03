# Idle Session Timeout System — Implementation Plan

## Context

WIS-CMS currently has no idle timeout mechanism. Sessions default to 120 minutes
(`config/session.php:35`), and there is no client-side inactivity detection. The
goal is to automatically log out users after **15 minutes** of inactivity, show a
**60-second warning modal** before termination, and provide a **keep-alive**
endpoint so users can extend their session.

**Key architectural fact:** This app uses **Sanctum token-based auth** (Bearer
tokens in localStorage), not cookie-based session auth. The frontend axios
interceptor already handles 401 → redirect to `/login`. The frontend idle timer
is the primary enforcement mechanism; the backend provides a keep-alive endpoint
and an optional session-validation middleware.

---

## Files to Create

| # | File | Purpose |
|---|------|---------|
| 1 | `app/Http/Controllers/Api/KeepAliveController.php` | Touches the session, returns lifetime |
| 2 | `app/Http/Middleware/EnsureActiveSession.php` | Optional middleware: checks session idle time |
| 3 | `resources/js/hooks/useIdleTimer.js` | React hook: activity listener + dual timers |
| 4 | `resources/js/components/IdleWarningModal.jsx` | Countdown modal with Stay / Logout buttons |
| 5 | `tests/Feature/IdleSessionTest.php` | PHPUnit tests for keep-alive + expiry |
| 6 | `tests/React/IdleWarningModal.test.jsx` | Vitest + Testing Library test for modal |

## Files to Modify

| # | File | Change |
|---|------|--------|
| 7 | `config/session.php:35` | Default lifetime 120 → 15 |
| 8 | `.env:49` | SESSION_LIFETIME=120 → 15 |
| 9 | `.env:40` (example) | SESSION_EXPIRE_ON_CLOSE=false → true |
| 10 | `routes/api.php` | Add `POST /api/auth/keep-alive` route |
| 11 | `bootstrap/app.php` | Register `EnsureActiveSession` middleware alias |
| 12 | `resources/js/App.jsx` | Wrap with IdleTimerProvider, render modal |
| 13 | `resources/js/api/auth.js` | Add `keepAlive()` API function |
| 14 | `resources/js/pages/auth/Login.jsx` | Show idle-timeout toast when `?reason=idle_timeout` |
| 15 | `package.json` | Add vitest + @testing-library devDependencies |
| 16 | `vite.config.js` | Add vitest config section |

---

## Phase 1: Laravel Backend

### 1.1 — Session Config (`config/session.php:35`)

Change the default from 120 to 15:

```php
'lifetime' => (int) env('SESSION_LIFETIME', 15),
'expire_on_close' => env('SESSION_EXPIRE_ON_CLOSE', true),
```

### 1.2 — Environment (`.env`)

```
SESSION_LIFETIME=15
SESSION_EXPIRE_ON_CLOSE=true
```

### 1.3 — KeepAliveController

**File:** `app/Http/Controllers/Api/KeepAliveController.php`

Simple invokable controller:
- Authenticates via Sanctum (`$request->user()`)
- Calls `$request->session()->put('last_activity_at', now()->timestamp)` to
  explicitly track user-initiated keep-alive
- Returns `['session_lifetime' => config('session.lifetime')]`

### 1.4 — Route (`routes/api.php`)

Inside the existing `auth:sanctum` + `EnsurePasswordChanged` group (line 73):

```php
Route::post('auth/keep-alive', [KeepAliveController::class, '__invoke']);
```

### 1.5 — EnsureActiveSession Middleware

**File:** `app/Http/Middleware/EnsureActiveSession.php`

- Reads `session('last_activity_at')` (set by keep-alive endpoint)
- If `now()->timestamp - last_activity_at > SESSION_LIFETIME * 60`:
  - `$request->session()->invalidate()`
  - `$request->session()->regenerateToken()`
  - Returns 401 JSON `{ message: 'Session expired due to inactivity.' }`
- If no `last_activity_at` exists in session, allows request through (grace
  period for sessions created before this feature)

**Registration** in `bootstrap/app.php`:
```php
$middleware->alias([
    // ... existing aliases
    'active-session' => EnsureActiveSession::class,
]);
```

**NOT applied globally.** Available for route-level use if desired. The frontend
idle timer is the primary enforcement.

---

## Phase 2: React Frontend

### 2.1 — API Function (`resources/js/api/auth.js`)

```js
export const keepAlive = () => api.post('/auth/keep-alive')
```

### 2.2 — useIdleTimer Hook (`resources/js/hooks/useIdleTimer.js`)

Custom hook that accepts `{ timeout, warningTimeout, onTimeout, onWarning }`.

**Activity events:** `mousemove`, `keydown`, `click`, `scroll`, `touchstart`

**Throttling:** Activity resets are throttled to at most once every 5 seconds
using a timestamp comparison (not lodash).

**Timer logic:**
- `warningTimeout` defaults to `timeout - 60` seconds (14 min for 15 min timeout)
- Two `setTimeout` chains: warning fires first, then timeout
- Any activity event resets both timers
- `reset()` function exposed for manual reset (e.g., after keep-alive)
- Listens to `visibilitychange` — when tab becomes visible again, checks if
  idle time exceeded timeout (handles sleeping/laptop-closed scenarios)
- Cleanup removes all event listeners and timers

**Background polling exclusion:** The hook ONLY listens to user-initiated DOM
events. API requests (polling, notifications) do NOT trigger activity resets.
The only way to reset the timer from code is calling `reset()` explicitly.

### 2.3 — IdleWarningModal (`resources/js/components/IdleWarningModal.jsx`)

Custom modal (same visual style as `ConfirmDialog.jsx`):
- Props: `open`, `remainingSeconds`, `onStayLoggedIn`, `onLogoutNow`
- Displays:
  - Icon: `AlertTriangle` from lucide-react (matches existing pattern)
  - Title: "Session Expiring Soon"
  - Message: "Your session is about to expire due to inactivity."
  - Countdown: "Logging out in {remainingSeconds} seconds..."
  - Progress bar showing remaining time
- Buttons:
  - "Stay Logged In" → calls `onStayLoggedIn`
  - "Log Out Now" → calls `onLogoutNow`
- Focus trap, Escape key handling (mirrors ConfirmDialog patterns)
- Countdown updates every second via `useEffect` with `setInterval`

### 2.4 — App.jsx Integration

```
AuthProvider
  → IdleTimer (wraps children, only active when authenticated)
    → Toaster
    → AppRouter
    → IdleWarningModal (rendered here, above routes)
```

The `IdleTimer` wrapper:
- Uses `useAuth()` to check `isAuthenticated`
- If not authenticated, renders children without the timer
- If authenticated, initializes `useIdleTimer` with:
  - `timeout: 15 * 60` (seconds)
  - `warningTimeout: 14 * 60` (seconds)
  - `onWarning`: sets modal open state
  - `onTimeout`: calls `logout()`, then `window.location.href = '/login?reason=idle_timeout'`
- Renders `IdleWarningModal` with:
  - `onStayLoggedIn`: calls `keepAlive()` API, then `reset()` on the timer, closes modal
  - `onLogoutNow`: calls `logout()`, redirects to `/login?reason=idle_timeout`

### 2.5 — Login Page (`resources/js/pages/auth/Login.jsx`)

Add a `useEffect` that checks `window.location.search` for
`reason=idle_timeout` and shows a toast:
```js
toast.info('Your session expired due to inactivity. Please sign in again.')
```

---

## Phase 3: Background Polling Exclusions

**No changes needed.** The design inherently excludes background requests:

1. The `useIdleTimer` hook listens to **DOM events only** (mouse, keyboard,
   scroll, touch). API responses don't trigger activity resets.
2. The keep-alive endpoint is only called when the user explicitly clicks
   "Stay Logged In" in the warning modal.
3. React Query's background refetches (staleTime, refetchOnWindowFocus) don't
   touch the idle timer.
4. The `visibilitychange` handler checks elapsed time but doesn't reset the
   timer — it only triggers logout if the timeout was exceeded while hidden.

---

## Phase 4: Testing

### 4.1 — PHPUnit Test (`tests/Feature/IdleSessionTest.php`)

Tests (using existing `AuthTest.php` patterns — `RefreshDatabase`, `makeUser()`,
Sanctum tokens):

1. **`test_keep_alive_returns_session_lifetime`** — Authenticated POST to
   `/api/auth/keep-alive` returns 200 with `session_lifetime`.

2. **`test_keep_alive_requires_authentication`** — Unauthenticated request
   returns 401.

3. **`test_expired_session_returns_401`** — Create a session with
   `last_activity_at` set to 20 minutes ago, apply `EnsureActiveSession`
   middleware, verify 401 response.

4. **`test_active_session_passes_middleware`** — Create a session with
   `last_activity_at` set to 5 minutes ago, verify request passes through.

### 4.2 — Vitest Setup

Add to `package.json` devDependencies:
```
vitest, @testing-library/react, @testing-library/jest-dom, jsdom
```

Add `test` script: `"test": "vitest run"`

Add vitest config to `vite.config.js`:
```js
test: {
  globals: true,
  environment: 'jsdom',
  setupFiles: ['./tests/React/setup.js'],
}
```

Create `tests/React/setup.js` with `@testing-library/jest-dom` import.

### 4.3 — React Test (`tests/React/IdleWarningModal.test.jsx`)

Tests:
1. **`renders warning message with countdown`** — Renders modal with
   `open={true}`, verifies "Session Expiring Soon" text and countdown display.

2. **`calls onStayLoggedIn when Stay button clicked`** — Clicks "Stay Logged
   In" button, verifies callback invoked.

3. **`calls onLogoutNow when Log Out Now button clicked`** — Clicks "Log Out
   Now", verifies callback invoked.

4. **`does not render when closed`** — Renders with `open={false}`, verifies
   modal content is not in the document.

5. **`counts down each second`** — Renders with `remainingSeconds={60}`, waits
   2 seconds, verifies countdown text updated to 58.

---

## Implementation Order

1. Config + .env changes (trivial, do first)
2. KeepAliveController + route
3. EnsureActiveSession middleware + registration
4. `useIdleTimer` hook
5. `IdleWarningModal` component
6. `App.jsx` integration
7. Login page idle-timeout toast
8. Vitest setup + React test
9. PHPUnit test
10. Lint check (`npm run lint`)

---

## Verification

- **Backend:** `php artisan test --filter=IdleSessionTest`
- **Frontend:** `npm run test` (vitest)
- **Lint:** `npm run lint`
- **Manual:** Log in, wait 14 min → modal appears; click "Stay Logged In" →
  timer resets; wait 15 min → auto-logout to `/login?reason=idle_timeout`
