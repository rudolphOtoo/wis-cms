# Diagnosis & Optimization Report

Branch: `feat/diocese-profiles` · Date: 2026-08-07
Scope: performance + correctness diagnosis of the diocese-profiles feature, targeted fixes, and regression verification.

---

## 1. Findings (Phase 1 Diagnosis)

### F1.1 — Attendance report queries aggregate-scan the entire table (perf bug, FIXED)

**Symptom:** The report endpoints (`attendance/stats`, `attendance/summary`, `reports/attendance-trends`, `attendance/sundays`) join against the `attendance_session_counts` view, which is defined as a plain `GROUP BY session_id` aggregate over **all** `attendance_records`.

**Root cause:** PostgreSQL cannot push branch/date predicates (or the `session_id` range) through the view's `GROUP BY`. Every report query forces a full scan + aggregate of every attendance record in the table before any filter applies — even when only a handful of sessions are in scope.

**Measured at scale** (scratch DB `wis_cms_diag`: 7,233 sessions / 283,400 records / 2 branches; 12-week window on "Big Branch"):

| Query | BEFORE (view) | AFTER (LATERAL) | Speedup |
|---|---|---|---|
| Summary pass A | 424.6 ms | 27.5 ms | ~15x |
| Sundays totals | 420.6 ms | 23.4 ms | ~18x |

(Initial 697–940 ms view figures were captured before the covering-index experiment; the dropped index only made things worse — existing indexes are optimal.)

**Why no index can fix it:** a `(session_id, is_present, deleted_at, member_id, child_id)` covering index made the view *slower* (120 ms vs 29 ms) because the GROUP BY still materializes every row. The LATERAL form instead uses `attendance_records_session_id_child_id_index` and `attendance_sessions_branch_date_batched_index`, which are already in both prod databases.

### F1.2 — View drops headcount sessions (correctness, already safe in LATERAL)

The view + `GROUP BY` produces zero rows for headcount-mode sessions that have no `attendance_records` rows, dropping their stored tallies. The LATERAL rewrite keeps them via `CASE WHEN s.attendance_mode = 'headcount' THEN ...`.

**Verification:** cross-checked all 7,233 sessions across every count column — **0 mismatches** between view and no-GROUP-BY LATERAL (`adult_count`, `children_count`, `total_count`, `records_total`). The `session_id IN (...)` eager-load path (`counts` relation / `AttendanceSessionCount` model) already pushes predicates through the view (1.2 ms) and was left untouched.

### F2.1 — Invalid `DIOCESE_PROFILE` fataled the app (bug, FIXED)

`config/diocese.php` did `require __DIR__."/profiles/{$profile}.php"` unconditionally, so `DIOCESE_PROFILE=bogus` produced `PHP Warning: require(...): Failed to open stream` and fatals during `config:show`/boot.

**Fix:** added an `is_file()` guard that falls back to the `wis` profile. Verified `bogus` → `wis`, empty → `wis`, `mcgh` → `mcgh`. Also verified both `php artisan config:cache` and `php artisan route:cache` work in all profiles and the strategy binding still resolves correctly against frozen cached config (`McghMemberNumberGenerator` for `mcgh`, `WisMemberNumberGenerator` for `wis`). Caches were cleared after verification.

### F2.2 — Headcount validation already correct (no backend change)

`MarkHeadcountRequest` enforces `required|integer|min:0|max:100000` per count and a `withValidator` mode check, with leader/cell authorization gates. **Fix executed on the frontend only:** added `max={MAX_TALLY_COUNT}` to the tally inputs and clamped `setTallyField` to `[0, 100000]` so the UI cannot drift beyond server-accepted bounds.

### F3.1 — Strategy binding latency (no change needed)

`DioceseServiceProvider` resolves `MemberNumberGenerator` via a cached singleton keyed on `Diocese::key()` — a single array read from cached config. Zero measurable latency; no change.

### F3.2 — PDF logo memory (no change needed)

`pdfLogoPath()` base64-encodes a ≤ 156 KB PNG (result data URI ≤ ~208 KB; embedded PDF usage ~160 KB) against `memory_limit=256M`. Negligible; no caching added.

### F3.3 — Frontend re-render storm on 500-row roster (FIXED)

`TakeAttendance.jsx` re-created `togglePerson`/`markAll` closures and re-mapped the entire people array inline on every render — every search keystroke and every toggle re-rendered all 500 roster rows. `togglePerson` also held a stale `saved` reference (from the original inline closure).

**Fix:** extracted `PersonRow` as a `memo()`-wrapped component; memoized `togglePerson`/`markAll` with `useCallback`; fixed the stale-closure `setSaved(false)` call. `fetchData` was already `useCallback`-wrapped; `NewSession.jsx` already had a `cancelled` flag guard, and `registry.js` already caches the diocese config in module scope.

---

## 2. Changes (Phase 2 Implementation)

| File | Change |
|---|---|
| `app/Support/AttendanceCounts.php` | **NEW.** Static `subquery(string $sessionAlias)` returning the correlated LATERAL SQL (byte-identical columns to the view: `adult_count`, `children_count`, `total_count`, `records_total`, `male_count`, `female_count`; no GROUP BY, LEFT-JOIN safe) plus `applyLateral()` helper. Docblock documents why the view is slow. |
| `app/Services/AttendanceStatsService.php` | Q1 join → `leftJoinLateral(AttendanceCounts::subquery(...))`. |
| `app/Services/AttendanceSummaryService.php` | Summary join → LATERAL. |
| `app/Http/Controllers/Api/AttendanceController.php` | Sundays join → LATERAL. |
| `app/Http/Controllers/Api/ReportsController.php` | `attendanceTrends` join → LATERAL (uses `AttendanceCounts::subquery('attendance_sessions')`). |
| `config/diocese.php` | `is_file()` guard; missing/invalid profile falls back to `wis`. |
| `resources/js/pages/attendance/TakeAttendance.jsx` | `PersonRow` memo component, `useCallback` on `togglePerson`/`markAll`, stale-closure fix, `MAX_TALLY_COUNT` constant with `max` attr + clamp. |

Untouched (already correct): `AttendanceSessionCount` model, `counts` relation, `DashboardService`, `MarkHeadcountRequest`, `DioceseServiceProvider`, `pdfLogoPath`.

---

## 3. Verification (Phase 3 Gates)

- **`pint`** — passed on all changed files.
- **`npm run lint`** — 0 errors (35 pre-existing warnings across the repo).
- **`npm run build`** — succeeded.
- **`composer test`** (full suite) — **231 tests / 710 assertions, all green** (was 231/710 before this pass).
- **Targeted suite first** (AttendanceStats, HeadcountAttendance, Reports, LeaderDashboard, DioceseProfile) — 57 tests / 224 assertions green.
- **`config:cache` + `route:cache`** — succeed in every profile; strategy binding resolves correctly against cached config; caches cleared after.
- **Correctness cross-check** — 0 mismatches on all 7,233 sessions; paginated sundays query generates valid SQL (LATERAL correctly scoped inside the count wrapper; 117 rows / 6 pages on diag data).

### Before / After

| Endpoint query | BEFORE | AFTER |
|---|---|---|
| Summary pass A (12-week, Big Branch) | 424.6 ms | 27.5 ms |
| Sundays totals (12-week, Big Branch) | 420.6 ms | 23.4 ms |
| Headcount sessions with zero records | dropped | preserved |
| Invalid `DIOCESE_PROFILE` | fatal | falls back to `wis` |
| 500-row roster toggle/search | full re-render per keystroke | memoized rows only |

---

## 4. Deploy Note

The `:8000` Docker stack bakes source into the `wis-cms-app` image (no volume mount). To ship these changes to the containerized environment, rebuild with `docker compose build` and restart the app/queue/scheduler services. The local host server (`:8002`, mcgh profile) picks up the PHP changes immediately and serves the freshly built assets from `public/build`.
