# Infrastructure & Operations

---

## Default environment (`.env.example`)

| Variable | Default | Performance note |
|----------|---------|------------------|
| `CACHE_STORE` | `database` | Cache reads/writes hit PostgreSQL |
| `SESSION_DRIVER` | `database` | Session I/O on DB |
| `QUEUE_CONNECTION` | `database` | Requires `queue:work`; better than `sync` |
| `FILESYSTEM_DISK` | `local` | Fine for single-server deploy |

Redis variables are present but **not enabled by default**.

---

## Production recommendations (`DEPLOYMENT.md`)

Documented but not enforced in code:

1. `composer install --no-dev --optimize-autologloader`
2. `php artisan config:cache`
3. `php artisan route:cache`
4. `php artisan view:cache`
5. **Redis** for cache and queue (recommended prose)
6. **Supervisor** — `php artisan queue:work --sleep=3 --tries=3`
7. **Cron** — `* * * * * php artisan schedule:run`

Missing from repo: nginx/Apache config samples, CDN, OPcache tuning, PHP-FPM pool sizing.

---

## Cache layers

| Layer | Status |
|-------|--------|
| Spatie permissions | 24h registry cache via `CACHE_STORE` |
| Application `Cache::` | **Not used** for dashboard, stats, categories |
| HTTP cache headers | **None** on API JSON |
| Browser CDN | Static assets served from app origin via Vite manifest |

**Implication:** Under load, PostgreSQL bears cache + session + queue + application queries.

**Production target:**

```env
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

Ensure Redis persistence and memory limits are configured for your host.

---

## Queue workers

**Critical for:**
- `SendBroadcastMessageJob` (SMS + email per recipient)
- `DispatchAttendanceFollowUpJob` (bulk follow-up pipeline)

**Failure mode:** `QUEUE_CONNECTION=sync` runs all delivery in the HTTP request.

**Verify in production:**
```bash
supervisorctl status wis-queue
php artisan queue:monitor database:default  # if using database queue
```

**Job gaps:**
- No `$timeout` on `SendBroadcastMessageJob` (SMS HTTP can hang)
- `DispatchAttendanceFollowUpJob` — `$tries = 1` (no retry on transient failure)

---

## Scheduler (off-request — good)

**File:** `routes/console.php`

| Command | Schedule |
|---------|----------|
| `birthdays:send` | Daily |
| `attendance:process-follow-ups` | Every 15 minutes |
| Audit FK commands | Weekly |

Scheduler must run via system cron. Follow-up command uses indexed query on `(follow_up_status, created_at)`.

---

## Database

- **PostgreSQL 16** via Docker Compose (port 5433) for local dev
- Migrations in `database/migrations/` — see [02-database-and-queries.md](./02-database-and-queries.md) for index gaps

**Ops checklist:**
- Connection pooling (PgBouncer) if many PHP-FPM workers
- `shared_buffers`, `work_mem` tuned for reporting queries
- Regular `VACUUM ANALYZE` on `attendance_records`, `message_recipients`

---

## CI pipeline

**File:** `.github/workflows/ci.yml`

Runs tests, `npm run build`, `composer install --optimize-autoloader`. Does not track bundle size regression or run load tests.

**Optional improvement:** Fail CI if `main-*.js` exceeds threshold (e.g. 600 KB gzip).

---

## Security vs performance

| Topic | Current | Tradeoff |
|-------|---------|----------|
| Sanctum token expiry | `null` | Fewer re-logins; stolen tokens valid until revoked |
| Login rate limit | 5/min | Good; extend to password reset |
| CORS / stateful API | `statefulApi()` enabled | Needed for SPA; minimal overhead |

---

## Monitoring (not in repo)

Recommended for production:

- Laravel Pulse or Telescope (staging only) for slow queries
- APM (Sentry, Datadog) on PHP and queue workers
- mNotify delivery failure alerts from logs
- Disk space on `storage/logs`

---

## Docker / deploy

- `docker-compose.yml` — PostgreSQL only; no Redis service defined
- No Laravel Octane, Horizon, or Sail in production path

**Scale path:** Add Redis container to compose; Horizon if queue depth monitoring needed; Octane only if concurrent WebSocket/long-poll not required and team can manage worker mode.

---

## SSL & static assets

Assets built to `public/build/` with hashed filenames (good for cache busting).

**Missing:** `Cache-Control: public, max-age=31536000, immutable` on nginx for `/build/assets/*`.

Fonts from Google Fonts bypass your CDN and add third-party latency.
