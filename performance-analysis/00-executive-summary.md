# Executive Summary

## Overall assessment

WIS-CMS is a well-structured Laravel + React SPA with solid conventions (eager loading on most lists, server pagination, streaming CSV exports, queued SMS/email jobs). Performance issues are **concentrated in a few hot paths** that will matter as membership and message volume grow—not in everyday CRUD for small branches.

The largest risks today:

1. **Attendance** — full-congregation load + per-session COUNT N+1 + unvirtualized UI.
2. **Messaging** — per-recipient DB inserts and sync queue blocking when `QUEUE_CONNECTION=sync`.
3. **Frontend** — **956 KB** single JS bundle (252 KB gzip); no code splitting.
4. **Dashboard/stats** — query fan-out and PHP loops instead of grouped SQL.

## Impact / effort matrix

| Priority | Finding | Impact | Effort |
|----------|---------|--------|--------|
| P0 | Attendance session count N+1 (accessors) | High | Low |
| P0 | Member list `has_user_account` N+1 | Medium–High | Low |
| P0 | `showSession` loads all active members | High at scale | Medium |
| P0 | Broadcast on `sync` queue | Critical in dev/small deploy | Low (config) |
| P1 | Per-row broadcast inserts | High at scale | Medium |
| P1 | Dashboard finance chart 12-query loop | Medium | Low |
| P1 | Missing indexes on `attendance_records` | Medium–High | Low |
| P2 | No JS code splitting (956 KB bundle) | High (first load) | Medium |
| P2 | Duplicate search API calls (6 pages) | Low–Medium | Low |
| P3 | DB cache/queue defaults | Medium under load | Low (ops) |

## Quick wins (Phase 1 — hours)

1. `MemberResource`: use `withExists('user')` instead of `$this->user()->exists()`.
2. Attendance counts: `withCount` on list queries or count eager-loaded `records`; fix accessors.
3. `UserController::roles`: `Role::with('permissions')`.
4. Remove duplicate search `useEffect` on six list pages.
5. `Http::timeout(10)` on mNotify client.
6. Production: `QUEUE_CONNECTION=database` or `redis` + running `queue:work`.

## What is already good

- Pagination on members, visitors, finance, messages, users, audit (~15–25 per page).
- `->with()` on most index endpoints.
- Export endpoints use `lazy()` streaming (members, finance).
- `SendBroadcastMessageJob` / `DispatchAttendanceFollowUpJob` are queued when configured.
- Spatie permission registry cached 24h.
- Follow-up scheduler composite index on `(follow_up_status, created_at)`.
- Lean npm dependencies (no lodash, moment, MUI).

## Bundle snapshot

| Asset | Size (min) | Gzip |
|-------|------------|------|
| `main-*.js` | 956.28 kB | 252.14 kB |
| `app-*.css` | 26.72 kB | 5.81 kB |

See [artifacts/bundle-sizes.txt](./artifacts/bundle-sizes.txt).

## Recommended reading order

1. This summary  
2. [06-prioritized-roadmap.md](./06-prioritized-roadmap.md) for implementation order  
3. Deep dives: [02-database-and-queries.md](./02-database-and-queries.md), [03-api-and-server.md](./03-api-and-server.md), [04-frontend.md](./04-frontend.md)
