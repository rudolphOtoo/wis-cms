# Database & Query Performance

**ORM:** Laravel Eloquent on PostgreSQL. No Prisma/Drizzle/Sequelize.

---

## P0 — Critical query issues

### 1. Attendance session count N+1

**Files:**
- `app/Models/AttendanceSession.php` lines 58–71
- `app/Http/Resources/AttendanceSessionResource.php` lines 20–22
- `app/Http/Controllers/Api/AttendanceController.php` lines 23–27 (index)

**Problem:** Accessors always execute fresh COUNT queries:

```php
public function getAdultCountAttribute(): int
{
    return $this->records()->whereNotNull('member_id')->where('is_present', true)->count();
}
```

`AttendanceSessionResource` exposes `adult_count`, `children_count`, `total_count` on every session. Index eager-loads `serviceType`, `recorder`, `branch` but **not** `records`.

**Impact:** ~3 COUNT queries × N sessions per page (e.g. 60 extra queries for 20 sessions).

**Also affected:** `DashboardController` (lines 47–52, 78–88), `AttendanceController::stats` (lines 259–272, 275–285, 317–328) — often loads `records` but accessors still re-query.

**Fix:**
- Add `withCount` subqueries on list/stats queries, **or**
- In accessors, if `relationLoaded('records')`, count the collection in PHP, **or**
- Remove accessors from API; expose only `withCount` columns.

**Effort:** Low (2–4 hours)

---

### 2. Member list N+1 — `has_user_account`

**File:** `app/Http/Resources/MemberResource.php` line 37

```php
'has_user_account' => $this->user()->exists(),
```

**Impact:** 1 extra query per member on `GET /api/members` (e.g. 20 members → 21 queries total for that field).

**Fix:** On `MemberController::index`, add `->withExists('user')` and in resource use `$this->user_exists` (or `when()` on loaded attribute).

**Effort:** Low (< 1 hour)

---

## P1 — High-impact query issues

### 3. Per-row inserts for broadcasts

**Files:**
- `MessageController::send` — loop `MessageRecipient::create()` + `SendBroadcastMessageJob::dispatch()` per recipient
- `CellController::message`, `DepartmentController::message` — same pattern
- `DispatchAttendanceFollowUpJob`, `SendBirthdayGreetings` — per-recipient creates

**Impact:** N INSERTs + N job dispatches for congregation-wide SMS (500 members = 500 round-trips in one HTTP transaction).

**Fix:** `MessageRecipient::insert([...])` in chunks; dispatch jobs in batches or one job with recipient IDs array.

**Effort:** Medium (4–8 hours)

---

### 4. Chart/stats query loops

**DashboardController** lines 93–109 — 6 iterations × 2 `sum('amount')` = **12 queries**:

```php
for ($i = 5; $i >= 0; $i--) {
    $m = $now->copy()->subMonths($i);
    $income = Transaction::where(...)->whereMonth(...)->sum('amount');
    $expense = Transaction::where(...)->whereMonth(...)->sum('amount');
}
```

**FinanceController::stats** — same pattern (lines ~279–290).

**MemberController::stats** — 7 separate `count()` queries (lines ~195–204).

**Fix:** Single query with `GROUP BY` year/month, or `selectRaw` + `groupBy`.

**Effort:** Low–Medium (2–4 hours each controller)

---

### 5. Leader dashboard N+1

**File:** `DashboardController::leaderDashboard` (~lines 193–290)

Inside `map()` over departments/cells:

```php
$deptSessions = AttendanceSession::where('branch_id', $dept->branch_id)
    ->where('department_id', $dept->id)
    ->withCount([...])
    ->orderByDesc('service_date')
    ->get();
```

**Impact:** 1 query per led department/cell.

**Fix:** One query for all relevant sessions with `whereIn('department_id', $ids)`, group in PHP.

**Effort:** Medium (3–5 hours)

---

### 6. Attendance stats — unbounded session load

**File:** `AttendanceController::stats` lines 317–320

```php
$allAdult = AttendanceSession::where('branch_id', $branchId)
    ->whereHas('serviceType', fn ($q) => $q->where('type', 'adult'))
    ->with(['serviceType', 'records'])
    ->get();
```

Loads **all** adult sessions + records for “top service” insight. Grows without bound.

**Fix:** SQL aggregate (`GROUP BY service_type_id`, `AVG` via subquery) or limit to last N sessions.

**Effort:** Medium (2–4 hours)

---

### 7. Portal attendance — fetch-all then slice

**File:** `PortalController` (~lines 100–111)

```php
$records = AttendanceRecord::query()
    ->where('member_id', $member->id)
    ...
    ->get()
    ->sortByDesc(...)
    ->take(50);
```

**Fix:** `orderByDesc` on joined `service_date` + `limit(50)` in SQL.

**Effort:** Low (1–2 hours)

---

### 8. Unbounded list endpoints

| Controller | Method | Issue |
|--------------|--------|-------|
| `CellController` | `index()` | `->get()` — no pagination |
| `DepartmentController` | `index()` | `->get()` — no pagination |
| `FinanceController` | `ledger()` | All transactions in date range for PDF |

**Effort:** Low (pagination) / Medium (ledger streaming or async PDF)

---

### 9. `UserController::roles` N+1

**File:** `UserController` (~lines 52–56)

```php
Role::orderBy('name')->get()->map(fn ($r) => [
    'permissions' => $r->permissions->pluck('name'),
]);
```

**Fix:** `Role::with('permissions')->orderBy('name')->get()`

**Effort:** Low (< 30 min)

---

### 10. `markAttendance` — N× `updateOrCreate`

**File:** `AttendanceController` lines 218–238

One DB round-trip per person in request body.

**Fix:** Batch upsert (`upsert()` on PostgreSQL) or single transaction with prepared bulk update.

**Effort:** Medium (3–5 hours)

---

## Indexes

### Existing (good)

| Table | Index |
|-------|-------|
| `members` | `(branch_id, status)` |
| `transactions` | `(branch_id, transaction_date)` |
| `visitors` | `(branch_id, follow_up_status)` |
| `attendance_sessions` | `(follow_up_status, created_at)` — scheduler |

### Recommended additions

| Table / columns | Rationale |
|-----------------|-----------|
| `attendance_records(session_id)` | FK counts, session detail, portal |
| `attendance_records(member_id)` | Portal, member history |
| `transactions(branch_id, type, transaction_date)` | Stats filter all three |
| `attendance_sessions(department_id)`, `(cell_id)` | Leader dashboard filters |

**Note:** `ilike '%term%'` search on `members.first_name`, `phone`, etc. will not use btree indexes; consider `pg_trgm` GIN if search is slow.

**Birthday cron:** `EXTRACT(MONTH/DAY FROM date_of_birth)` — full scan without functional index.

**Migration reference:** `database/migrations/2026_05_16_164939_create_attendance_records_table.php` — FKs only, no explicit indexes on `session_id` / `member_id`.

---

## Good patterns (keep)

| Pattern | Example |
|---------|---------|
| Eager loading | `MemberController`, `FinanceController`, `MessageController` index |
| Pagination | Most `index()` methods, `per_page` 15–20 |
| Streaming export | `MemberController::export`, `FinanceController::export` — `lazy()` |
| Aggregates | `DashboardController` top categories — `selectRaw` + `groupBy` |
| Locking | `Member` model `lockForUpdate()` on member number generation |
| `withCount` | `MessageController` index — delivered/failed counts |

---

## Raw SQL usage

| Location | Purpose |
|----------|---------|
| `ProcessPendingAttendanceFollowUps` | `whereRaw` interval for follow-up cutoff |
| `SendBirthdayGreetings` | `EXTRACT(MONTH/DAY FROM date_of_birth)` |
| `FinanceController`, `DashboardController` | `selectRaw` category totals |
| Migrations | Partial unique indexes on attendance sessions |

---

## Suggested EXPLAIN targets (production follow-up)

```sql
-- Member search (if slow)
EXPLAIN ANALYZE SELECT * FROM members
WHERE branch_id = $1 AND (first_name ILIKE '%x%' OR last_name ILIKE '%x%')
LIMIT 20;

-- Attendance session list with counts
EXPLAIN ANALYZE SELECT attendance_sessions.*,
  (SELECT COUNT(*) FROM attendance_records WHERE session_id = attendance_sessions.id AND is_present = true AND member_id IS NOT NULL) AS adult_count
FROM attendance_sessions WHERE branch_id = $1 ORDER BY service_date DESC LIMIT 20;
```

Save detailed plans under `artifacts/explain-samples.sql` when run against production-like data volumes.
