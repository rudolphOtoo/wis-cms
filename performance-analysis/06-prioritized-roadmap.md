# Prioritized Remediation Roadmap

Implementation should happen in **separate PRs** after this audit. Order below balances impact, risk, and effort.

---

## Phase 1 — Quick wins (hours, low risk)

| # | Task | Files | Effort |
|---|------|-------|--------|
| 1.1 | `withExists('user')` on member index; update `MemberResource` | `MemberController`, `MemberResource.php` | 1h |
| 1.2 | Fix attendance counts: `withCount` on queries + stop accessor DB hits | `AttendanceSession.php`, `AttendanceSessionResource.php`, `AttendanceController`, `DashboardController` | 3h |
| 1.3 | `Role::with('permissions')` in roles endpoint | `UserController.php` | 30m |
| 1.4 | Remove duplicate search `useEffect` (6 pages) | `members`, `visitors`, `finance`, `children`, `admin/Users`, `admin/AuditLog` index.jsx | 2h |
| 1.5 | `Http::timeout(10)->retry(2, 100)` on mNotify | `MnotifySmsService.php` | 30m |
| 1.6 | Document/enforce non-sync queue in production | `.env.example`, `DEPLOYMENT.md`, deploy checklist | 1h |
| 1.7 | Portal attendance: SQL `limit(50)` + order | `PortalController.php` | 1h |

**Expected outcome:** Fewer queries on daily lists; no double search API calls; SMS won't hang workers indefinitely.

---

## Phase 2 — Scale paths (days)

| # | Task | Files | Effort |
|---|------|-------|--------|
| 2.1 | Paginated/searchable `showSession` + virtualized `TakeAttendance` | `AttendanceController`, `TakeAttendance.jsx` | 1–2d |
| 2.2 | Batch `MessageRecipient::insert` + batched job dispatch | `MessageController`, `CellController`, `DepartmentController` | 1d |
| 2.3 | Consolidate dashboard/finance chart queries (single `GROUP BY`) | `DashboardController`, `FinanceController` | 4h |
| 2.4 | Fix leader dashboard session N+1 | `DashboardController::leaderDashboard` | 4h |
| 2.5 | Limit or aggregate attendance stats `allAdult` query | `AttendanceController::stats` | 4h |
| 2.6 | Migration: indexes on `attendance_records`, composite on `transactions` | new migration | 2h |
| 2.7 | `React.lazy` for routes; lazy-load Recharts | `AppRouter.jsx`, chart pages | 1d |
| 2.8 | Vite `manualChunks` (vendor, charts) | `vite.config.js` | 2h |
| 2.9 | Paginate cells/departments index | `CellController`, `DepartmentController` | 3h |
| 2.10 | `upsert` or bulk update for `markAttendance` | `AttendanceController` | 4h |

**Expected outcome:** App remains responsive with 500+ members; initial JS load drops significantly after 2.7–2.8.

---

## Phase 3 — Production hardening (ongoing)

| # | Task | Notes |
|---|------|-------|
| 3.1 | Redis for cache, session, queue | Update compose + `.env.production` |
| 3.2 | Supervisor queue workers + monitoring | Required for messaging |
| 3.3 | Queue PDF generation (giving statement, ledger) | DomPDF off request thread |
| 3.4 | API throttle on `forgot-password`, `reset-password`, `messages/send` | `Route::middleware('throttle:...')` |
| 3.5 | `Cache::remember` for dashboard (60–120s TTL) | Invalidate on finance/attendance mutations |
| 3.6 | nginx `Cache-Control` for `/build/assets/*` | Immutable hashed assets |
| 3.7 | Self-host or subset Google Fonts | `app.css` |
| 3.8 | Optional: Laravel Horizon, Pulse, bundle size CI gate | When team size/traffic warrants |

---

## Priority reference (P0–P3)

### P0 — Fix before large rollout

- Attendance count N+1
- Member `has_user_account` N+1
- Full congregation `showSession`
- Broadcast on `sync` queue

### P1 — Fix before 300+ members or heavy messaging

- Per-row broadcast inserts
- Dashboard/stats query loops
- Leader dashboard N+1
- Attendance stats unbounded load
- Missing `attendance_records` indexes
- Unbounded cell/department lists

### P2 — UX and first-load

- 956 KB JS bundle / no code splitting
- Duplicate search fetches
- Auth context re-renders
- Large non-virtualized lists (attendance UI)

### P3 — Ops and hardening

- DB cache/queue defaults
- No HTTP/API caching
- Rate limits beyond login
- mNotify timeout (also in Phase 1)
- Inline PDF
- Deploy artisan caches (process, not code)

---

## Testing checklist (per phase)

**Phase 1**
- [ ] `GET /api/members` — query count stable when paginating (Laravel Debugbar or `DB::listen`)
- [ ] `GET /api/attendance` — no 3×N count queries
- [ ] Search on members page — single network request per debounced keystroke
- [ ] SMS send with fake HTTP — times out at 10s

**Phase 2**
- [ ] Take attendance with 200+ members — acceptable scroll/toggle latency
- [ ] Broadcast to 100 recipients — request returns quickly; jobs process in queue
- [ ] `npm run build` — main chunk < 400 KB gzip (target)
- [ ] Dashboard load — < 10 DB queries (target)

**Phase 3**
- [ ] Redis connected; cache/queue/session use redis
- [ ] `queue:work` running; failed jobs table monitored
- [ ] PDF download does not block other users (queued)

---

## Out of scope for this audit

- k6 / Artillery load tests
- Production `EXPLAIN ANALYZE` (templates in 02-database doc)
- Lighthouse CI
- Implementing fixes in this branch (docs only)
