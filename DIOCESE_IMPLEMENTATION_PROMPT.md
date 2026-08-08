# ROLE
You are a Senior Laravel Architect implementing a "Modular Monolith + Diocese Profile"
architecture in an existing Laravel 13 + React 19 application. Follow the repository's
existing conventions precisely. Work autonomously, keep tests green at every phase, and
stop at the end for review (do NOT merge to `main` yourself).

# AUTHORITATIVE PLAN
Read `DIOCESE_CUSTOMIZATION_PLAN.md` in the repo root first. It is the source of truth
for everything below (sections 1–5).

# CONTEXT
This is WIS-CMS, a single-product Church Management System (Laravel 13 API + React 19 SPA,
PostgreSQL, Spatie Permission, Sanctum, Docker-delivered via GHCR). A Methodist diocese
from a different district wants to adopt it with different operations logic. They host
locally (one install = one server + one DB) and their requirements arrive incrementally
("as-and-when they tell us"). Everything is delivered via the Docker image; no per-diocese
code forks, ever.

# REQUIREMENTS (from the diocese)
1. Local hosting per diocese; behavior chosen at deploy time by `DIOCESE_PROFILE` env var.
2. Attendance logic is DIFFERENT: ushers COUNT at the door (tally), not a per-member
   register. They record `male_count`, `female_count`, `children_count`. Children's
   ministry is NOT a cell — children is just a number. Sessions are church-wide
   (no cell/department binding).
3. Their own data on local spin-up: their branch, roles, service types, finance categories,
   member records.
4. Per-diocese branding: logo + favicon, bundled in the image, profile-driven.
5. The default `wis` profile must remain behavior-identical — existing installs are never broken.

# ARCHITECTURE SPEC
Three layers, all driven by the active profile (`config/diocese.php` loads
`config/profiles/{slug}.php`, slug = `DIOCESE_PROFILE`, default `wis`):

1. CAPABILITIES — config flags + strings (features, labels, defaults). Backend AND SPA read them.
2. STRATEGIES — behavioral overrides behind PHP interfaces, bound in the container per profile
   (e.g. member-number generation).
3. MODULES — self-contained, flag-gated feature packages under `app/Diocese/Modules/<Name>/`,
   inert (no tables/routes/UI) when their capability flag is off.

Guardrails (non-negotiable):
- Core stays generic — no WIS-specific wording in core code; WIS wording lives in
  `profiles/wis.php` strings.
- Capabilities drive UI only; Spatie permissions (`permission:` middleware, FormRequest
  `authorize()`) drive authorization. Never use a capability in an authz check.
- New entities go in module migrations; core tables stay shared.
- `DIOCESE_PROFILE` is read before `php artisan config:cache` (automatic in Docker boot).
- Seeders must keep `PermissionRegistrar::forgetCachedPermissions()`.

# PRE-RESEARCHED INTEGRATION POINTS (already mapped — use these)
- Member numbers hardcoded: `app/Models/Member.php` `booted()` (lines ~44–69), `WIS-YYYY-NNNN`.
- Seeders to make profile-driven: `database/seeders/RolesAndPermissionsSeeder.php`,
  `ServiceTypeSeeder.php`, `FinanceCategorySeeder.php`, `BranchSeeder.php`.
- Attendance (register-based today): `AttendanceController`, `AttendanceSession`,
  `AttendanceRecord`, `app/Http/Requests/Attendance/CreateAttendanceSessionRequest.php`
  (enforces `cell_id` for adult/children service types in `withValidator()`),
  `app/Services/AttendanceStatsService.php`, `AttendanceController::sundays()`,
  `AttendanceSummaryService.php`, `DashboardService.php`, `ReportsController.php`,
  `app/Http/Resources/AttendanceSessionResource.php`.
- Frontend attendance: `resources/js/pages/attendance/TakeAttendance.jsx` (per-person
  checklist), `NewSession.jsx` (cell/dept pickers).
- Service providers: `bootstrap/providers.php` (add the Diocese provider there).
- PDF logo: `app/Http/Controllers/Controller.php` `pdfLogoPath()` (base64 of
  `public/images/wis-logo.png`), used by MemberController/ReportsController/FinanceController
  PDFs. PDF blades already use `$logoPath`.
- Frontend logo hardcoded: `Sidebar.jsx`, `Login.jsx`, `Portal.jsx` (`/images/wis-logo.png`).
  Blade host + favicon: `resources/views/layouts/dashboard.blade.php`,
  `resources/views/welcome.blade.php`.
- SPA auth/meta: `AuthController::me()`, `UserResource`, `resources/js/context/AuthContext.jsx`,
  `resources/js/components/layout/Sidebar.jsx` (MAIN_NAV/FINANCE_NAV/ADMIN_NAV + permission filter),
  `resources/js/routes/AppRouter.jsx`.
- Docker: `Dockerfile` (`COPY . .` stage 3 — everything ships in the image),
  `.dockerignore`, `docker-compose.deploy.yml`, `docker/entrypoint.sh` (line 17–18 HARDCODES
  `app:data-migrate --import` + `import:csv WIS_Ayeduase.csv` on every boot — must become
  profile-aware).
- Config precedent: `config/church.php` + `CHURCH_NAME` env vars.

# PHASED IMPLEMENTATION TASKS
Work IN THIS ORDER on branch `feat/diocese-profiles` (create it from `main` first).
Each phase must leave the full test suite green.

## Phase 1 — Profile foundation (behavior-neutral)
- `config/diocese.php`:
  ```php
  $profile = env('DIOCESE_PROFILE', 'wis');
  return array_merge(['key' => $profile], require __DIR__."/profiles/{$profile}.php");
  ```
- `config/profiles/wis.php`: captures CURRENT behavior verbatim. Structure: `key`, `label`,
  `capabilities` (incl. `attendance.default_mode = 'register'`, `modules => [...]`),
  `strings` (incl. `app.title`, `app_name`, `logo`, `favicon`, `reports.footer`),
  `reference_data` (roles map, service_types, finance_categories) — populated from the
  existing seeders exactly.
- `config/profiles/mcgh.php`: `attendance.default_mode = 'headcount'`, its own reference
  data, logo/favicon paths.
- `app/Diocese/Diocese.php` helper: `key()`, `capability(path, default)`, `string(path, default)`
  reading `config('diocese.*')` via `data_get`.
- `app/Diocese/Providers/DioceseServiceProvider.php` (registered in `bootstrap/providers.php`).
- Add `DIOCESE_PROFILE=mcgh` commented to `.env.example`.
- **Acceptance:** suite green; default behavior unchanged.

## Phase 2 — Strategy extraction (member numbers)
- `app/Diocese/Contracts/MemberNumberGenerator.php`:
  `generate(Member $member): string` and `latestPattern(int $year): string`.
- `WisMemberNumberGenerator` (move today's `Member::booted()` logic verbatim, incl. the
  `withTrashed` + `lockForUpdate` serial logic) and `McghMemberNumberGenerator`
  (format `MCC/YYYY/NNNNN`, 5-digit serial — flag this as an assumption to confirm).
- Bind in `DioceseServiceProvider::register()` via `match (Diocese::key())`.
- Refactor `Member::booted()` to delegate to the bound strategy.
- **Acceptance:** new members get the profile's format; WIS format unchanged.

## Phase 3 — Profile-driven reference data
- `RolesAndPermissionsSeeder`: read `capabilities` role→permission map from profile;
  permission list = union; keep `forgetCachedPermissions()`. `wis.php` mirrors today exactly.
- `ServiceTypeSeeder` / `FinanceCategorySeeder` / `BranchSeeder` read from profile
  `reference_data`.
- **Acceptance:** seeding identical for `wis`; different for `mcgh`.

## Phase 4 — Attendance dual-mode (largest)
- Migration on `attendance_sessions`: `attendance_mode` string default `'register'`;
  `male_count`, `female_count`, `children_count` unsignedInteger nullable.
- PostgreSQL view `attendance_session_counts` producing per-session `adult_count`,
  `children_count`, `total_count`, `male_count`, `female_count` that works for BOTH modes
  (headcount: stored counts; register: `COUNT(*) FILTER (WHERE is_present AND deleted_at IS NULL)`
  over `attendance_records`).
- Refactor `AttendanceStatsService`, `AttendanceController::sundays()`,
  `AttendanceSummaryService`, `DashboardService`, `ReportsController` attendance queries to
  join the view (removes duplicated conditional SQL).
- Model accessors `getAdultCountAttribute`/`getChildrenCountAttribute`/`getTotalCountAttribute`
  branch on `attendance_mode`.
- `CreateAttendanceSessionRequest`: in headcount mode, drop the `cell_id`/`department_id`
  requirements; `attendance_mode` accepted (default from profile).
- `AttendanceController`: `createSession` stores mode (church-wide for headcount); NEW
  `POST /api/attendance/sessions/{id}/headcount` validating `male_count`, `female_count`,
  `children_count` (ints ≥ 0, mode-aware authorize); `showSession` returns mode + counts and
  no `people` roster for headcount; `markAttendance` (register) unchanged.
- `AttendanceSessionResource`: expose `attendance_mode` + counts.
- Frontend: `NewSession.jsx` hides cell/dept pickers in headcount mode; `TakeAttendance.jsx`
  renders a tally form (Men / Women / Children + live total) when `attendance_mode === 'headcount'`,
  keeping the existing checklist otherwise and preserving the follow-up badges.
- **Acceptance:** both modes produce the same stats/report shape; headcount sessions don't
  require a cell; WIS flow unchanged.

## Phase 5 — Branding
- Profile strings `logo`/`favicon` (`/images/mcgh-logo.png`, `/favicon-mcgh.png`).
- `Controller::pdfLogoPath()` resolves `public_path(trim(Diocese::string('logo'), '/'))`
  (all PDFs inherit).
- `dashboard.blade.php`: inject `window.APP_META = { logo, app_name }` from the profile; make
  favicon + apple-touch-icon profile-driven; same for `welcome.blade.php`.
- `Sidebar.jsx`, `Login.jsx`, `Portal.jsx` read `window.APP_META.logo` (WIS fallback).
- Add placeholder mcgh logo/favicon assets in `public/` (clearly marked placeholders).
- **Acceptance:** mcgh logo shows pre-login, in-app, and in PDFs under `DIOCESE_PROFILE=mcgh`.

## Phase 6 — Frontend capability flags
- `GET /api/bootstrap` (authenticated) returning `{ user, diocese: { key, label,
  capabilities, strings } }` (or extend `me`).
- `resources/js/diocese/registry.js` storing it on login; `Sidebar.jsx` and `AppRouter.jsx`
  filter nav/routes by capability alongside the existing permission checks.
- **Acceptance:** disabled capabilities hide their nav/route; no authz impact.

## Phase 7 — Entrypoint profile-awareness
- `docker/entrypoint.sh`: only run `app:data-migrate --import` and `import:csv
  WIS_Ayeduase.csv` when the active profile is `wis`; for `mcgh`, skip (or import a
  profile-declared data file). Never load WIS data into a diocese install.

## Phase 8 — Module skeleton (extensibility proof)
- `DioceseServiceProvider` registers flag-gated module providers.
- One reference module `app/Diocese/Modules/Confirmations/` (migration + model + controller +
  routes + seeder + React page), self-gating on `capabilities.modules.confirmations`; default
  OFF in both profiles.
- **Acceptance:** module disabled → no table, no routes (404), no nav; enabling it in a
  profile activates it.

## Phase 9 — Tests
- Attendance mode equivalence (register vs headcount → same stats/report shape).
- Profile seed tests (each profile seeds its own roles/types/categories; `wis` identical to today).
- Member-number per profile.
- Branding: `pdfLogoPath()` resolves the profile's file; `window.APP_META` renders profile logo.
- Module gate: disabled module → no route/table.

## Phase 10 — Docs
- New `DIOCESE_SETUP.md` — diocese onboarding runbook (Docker path): prerequisites; fill
  `.env` (`DIOCESE_PROFILE`, `CHURCH_NAME`, `APP_NAME`, `ADMIN_*`, DB, secrets); pull & start
  (`docker compose -f docker-compose.deploy.yml pull && up -d`); seed
  (`db:seed --class=ProductionSeeder --force`); import their member data
  (`import:church-data members.csv --dry-run` then real); verify (login, branding, headcount);
  update path (pin `IMAGE_TAG`); troubleshooting (config cache, permission cache, password).
- Update `DEPLOYMENT.md` (add `DIOCESE_PROFILE` + profile-aware seeding) and `.env.example`.
- New `CAPABILITY_INVENTORY.md` — which profile has which flags/modules/strategies.

# VERIFICATION (before you stop)
1. `./vendor/bin/pint` clean.
2. `npm run lint` clean.
3. `composer test` (Pest) — full suite green.
4. `docker compose build && docker compose up -d` smoke test with `DIOCESE_PROFILE=mcgh`:
   no WIS data seeded; headcount attendance end-to-end; mcgh logo in UI + PDF; `wis` profile
   still behaves identically.

# DELIVERABLES
- All code on `feat/diocese-profiles`, committed incrementally with clear messages (do NOT
  merge to `main`, do NOT open/push the PR unless asked).
- Tests for every phase.
- The four docs above.
- A summary at the end: what was built per phase, assumptions made (e.g. MCC number format),
  and anything the diocese must confirm.

# OUT OF SCOPE (do not implement unless asked)
- `WorkflowPolicy` approval flows / event hooks (future).
- Admin logo-upload screen (future enhancement).
- Any changes to existing WIS behavior or production data.
- Pushing images / CI changes / deploying anywhere.
