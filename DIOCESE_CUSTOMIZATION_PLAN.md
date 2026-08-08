# Diocese Customization — Discussion & Execution Plan

**Date:** 2026-08-07
**Status:** Approved — first pass covers foundation + known Methodist diocese (mcgh) work

---

## 1. What Was Discussed

### 1.1 The situation

A Methodist diocese from a different district wants to adopt WIS-CMS. Their internal
processes and workflows differ from ours. They will host the system locally, and they want
their own data and their own features on their local install.

We agreed to build this **as-and-when they tell us their logic** — each new piece of their
operations is captured and implemented reactively, not all up front.

### 1.2 Their requirements

- **Local hosting** — each diocese runs the system on its own server/database.
- **Their own data** — on spin-up they get their own church data (branch, service types,
  finance categories, roles, member records).
- **Different attendance logic:**
  - Ushers **count at the door** (tally) instead of a per-member register.
  - They record **number of males** and **number of females** who came to service.
  - **Children's ministry is NOT treated as a cell** — they simply input the **number of
    children** present at service.

### 1.3 Decisions we made

1. **Architecture: "Modular Monolith + Diocese Profile."**
   One codebase. A `DIOCESE_PROFILE` env var (default `wis`) selects the active diocese
   profile at deploy time. Behavior is driven by three layers:
   - **Capabilities** (config feature flags + labels) — what's on/off and wording.
   - **Strategies / Hooks** (interface + container binding) — algorithmic differences
     (e.g. member-number scheme).
   - **Modules** (self-contained, flag-gated) — brand-new features without touching core.

   Rejected: pure feature flags (can't express new tables), composer-package plugins
   (too heavy), and multi-tenant SaaS (not needed — each diocese hosts locally).

2. **Attendance becomes dual-mode in core:** `register` (current WIS per-member register)
   and `headcount` (their tally). Mode is a soft default per profile, overridable per session.

   | | Register mode (WIS) | Headcount mode (MCC) |
   |---|---|---|
   | Recorded via | `attendance_records` per member/child | `male_count`, `female_count`, `children_count` on the session |
   | Session scope | cell/department required | church-wide (whole congregation) |
   | Children | Children Ministry cell roster | plain children count |
   | Capture UI | per-person checklist | tally form: Men / Women / Children |

   Counts are normalized through a single PostgreSQL view so all stats/reports/dashboard
   work for both modes without code branching.

3. **Build model is incremental.** The profile system, strategy contracts, and dual-mode
   attendance are the "plumbing" built now. Everything after is additive: each new branch
   request is classified into a capability flag, a strategy implementation, or a module —
   then added behind that branch's profile and tested against the default profile.

4. **Per-diocese branding.** Each diocese gets its own **logo and favicon**, driven by the
   profile. Logos are **bundled in the image** (shipped in `public/images/`), and the profile
   points to them — zero extra config on their server. Replacing the file on the diocese
   server rebrands the app.

5. **Guardrails:**
   - No forked code; the only per-install difference is profile files + modules.
   - Default `wis` profile stays behavior-identical — existing installs are never broken.
   - Capabilities drive UI only; Spatie permissions drive authorization.
   - Promote shared patterns into core (flag-gated) instead of duplicating them per profile.
   - Keep a capability inventory so a feature is never built twice.

### 1.4 Per-diocese branding (logo + favicon)

**Profile strings** — each profile declares its own logo and favicon:

```php
// config/profiles/wis.php
'strings' => [
    'app.title' => 'WIS-CMS',
    'app_name'  => 'Wesleyan International Society',
    'logo'      => '/images/wis-logo.png',
    'favicon'   => '/favicon.png',
],
// config/profiles/mcgh.php
'strings' => [
    'app.title' => 'MCC-CMS',
    'app_name'  => 'Methodist Church Ghana',
    'logo'      => '/images/mcgh-logo.png',
    'favicon'   => '/favicon-mcgh.png',
],
```

**Every logo usage covered:**

| Location | Today | Change |
|---|---|---|
| `app/Http/Controllers/Controller.php` `pdfLogoPath()` | base64-embeds `public/images/wis-logo.png` into all PDFs | read path from `Diocese::string('logo')` |
| `resources/views/layouts/dashboard.blade.php` (favicon + apple-touch-icon) | hardcoded `favicon.png` | read from `Diocese::string('favicon')` |
| `resources/views/welcome.blade.php` (favicon) | hardcoded `favicon.png` | read from `Diocese::string('favicon')` |
| `Sidebar.jsx`, `Login.jsx`, `Portal.jsx` | hardcoded `/images/wis-logo.png` | read from injected meta |
| `resources/views/pdf/*.blade.php` | already use `$logoPath` variable | no change |

**Frontend delivery (works pre-login):** inject the profile meta once in the SPA host layout
so Login/Sidebar/Portal all read the same source without an extra fetch:

```php
<script>window.APP_META = {
  logo: "{{ Diocese::string('logo') }}",
  app_name: "{{ Diocese::string('app_name') }}",
};</script>
```

`Sidebar.jsx`, `Login.jsx`, `Portal.jsx` read `window.APP_META.logo` (with a WIS fallback).

**Assets:** ship the diocese logo/favicon in the image (`public/images/mcgh-logo.png`,
`public/favicon-mcgh.png`); the profile points at them. The Docker build already bakes
`public/` in, so `docker-compose.deploy.yml` needs no changes.

---

## 2. Execution Plan

### Phase 1 — Profile foundation (behavior-neutral)

- `config/diocese.php` resolving `DIOCESE_PROFILE` (default `wis`).
- `config/profiles/wis.php` — captures current behavior verbatim.
- `config/profiles/mcgh.php` — diocese profile (headcount attendance, their reference data,
  their logo/favicon).
- `app/Diocese/Diocese.php` helper (`key()`, `capability()`, `string()`).
- `DioceseServiceProvider` registered in `bootstrap/providers.php`.
- `DIOCESE_PROFILE` added to `.env.example`.
- Ship `public/images/mcgh-logo.png` + `public/favicon-mcgh.png` in the image.
- **Gate:** full test suite stays green.

### Phase 2 — Strategy extraction

- `MemberNumberGenerator` contract + WIS implementation (today's logic moved verbatim) +
  MCC implementation (their format). Refactor `Member::booted()` to use it.

### Phase 3 — Profile-driven reference data

- `RolesAndPermissionsSeeder`, `ServiceTypeSeeder`, `FinanceCategorySeeder` read their data
  from the active profile instead of hardcoded arrays. `wis.php` mirrors today exactly.

### Phase 4 — Attendance dual-mode (largest)

- Migration: `attendance_mode` + `male_count` / `female_count` / `children_count`.
- PostgreSQL view normalizing counts for both modes; refactor stats, Sundays list, dashboard,
  and reports to use it.
- Relax session-creation rules for headcount (church-wide, no cell/department);
  new headcount endpoint to save Men / Women / Children.
- Frontend: tally form for headcount mode; session form hides cell/department pickers.

### Phase 5 — Frontend capability flags + branding

- `/api/bootstrap` returns the diocese's capabilities and labels to the SPA.
- Sidebar and router filter by capability alongside existing permission checks.
- `Controller::pdfLogoPath()` reads `Diocese::string('logo')` — all PDFs pick up the
  diocese logo.
- Inject `window.APP_META` in `dashboard.blade.php`; `Sidebar`, `Login`, `Portal` read the
  logo from it; favicon + apple-touch-icon read from `Diocese::string('favicon')`.
- Tests: `pdfLogoPath()` resolves the profile's file; blade meta renders the profile logo.

### Phase 6 — Extensibility proof + tests + docs

- One reference module (e.g. Confirmations) built end-to-end to prove the module pattern.
- Tests: register vs. headcount produce the same stats shape; profile seed tests;
  module gate (disabled module = no route/table); member numbers per profile.
- Diocese onboarding runbook in `DEPLOYMENT.md`: set `.env` → `docker compose up` →
  `ProductionSeeder` → `import:church-data` for their member data.

### After the foundation

Each branch request → classify (capability flag / strategy / module) → add behind that
branch's profile → test in default + branch profiles → deploy to that branch's local install.

---

## 3. Onboarding a New Diocese (their local install)

```bash
cp .env.example .env            # set DIOCESE_PROFILE, CHURCH_NAME, ADMIN_* ...
docker compose -f docker-compose.deploy.yml up -d   # migrations run on boot
docker compose exec app php artisan db:seed --class=ProductionSeeder --force
docker compose exec app php artisan import:church-data members.csv --branch="..." --dry-run
```

---

## 4. To Collect from the Diocese (as they tell us)

- [ ] Exact member-number format
- [ ] Full role/permission set
- [ ] Service types and finance categories
- [ ] Whether headcount sessions should ever be department-scoped (meetings)
- [ ] New modules needed (confirmations, circuits, transfers, …)
- [ ] Member data import file format (CSV/XLSX)

---

## 5. Implementation Plan (Branch → Test → Merge → Deliver)

### 5.1 Git workflow

We implement everything on a feature branch, test it, then merge to `main`:

1. Create branch `feat/diocese-profiles` off `main`.
2. Implement all phases on the branch (never commit to `main` directly).
3. Test locally: `composer test`, `npm run lint`, plus a real
   `docker compose build && up` smoke test with `DIOCESE_PROFILE=mcgh`.
4. Open a pull request, review, merge to `main`.
5. Merging to `main` **auto-publishes the Docker images** — CI
   (`.github/workflows/docker-publish.yml`) builds and pushes
   `ghcr.io/.../wis-cms-app` and `-webserver` (tagged `latest` + short SHA).
   No manual publish step.

### 5.2 Container delivery (confirmed)

Everything we implement ships inside the Docker image, so a diocese's
local spin-up gets it automatically:

- `Dockerfile` stage 3 (`app`) runs `COPY . .`; `.dockerignore` does NOT
  exclude `config/`, `app/`, `database/`, `public/`, or `resources/js/`.
- The image therefore contains: `config/profiles/*.php`, `app/Diocese/`,
  all migrations, the built frontend bundle, and the diocese logo/favicon.
- Diocese install = `docker compose -f docker-compose.deploy.yml pull && up -d`.

**Gotcha to fix:** `docker/entrypoint.sh` (lines 17–18) hardcodes WIS data
seeding on every boot (`app:data-migrate --import`,
`import:csv WIS_Ayeduase.csv`). This must become profile-aware so a diocese
boot does not load WIS's membership data. Handled in Phase 7 below.

### 5.3 Execution order (all on `feat/diocese-profiles`)

| # | Phase | Deliverable |
|---|---|---|
| 1 | Profile foundation | `config/diocese.php`, `config/profiles/{wis,mcgh}.php`, `app/Diocese/Diocese.php`, `DioceseServiceProvider`, `DIOCESE_PROFILE` in `.env.example`; suite stays green |
| 2 | Strategy extraction | `MemberNumberGenerator` contract + WIS/MCC implementations; refactor `Member::booted()` |
| 3 | Profile-driven reference data | `RolesAndPermissionsSeeder`, `ServiceTypeSeeder`, `FinanceCategorySeeder` read from the active profile |
| 4 | Attendance dual-mode | migration (`attendance_mode` + counts), count-normalization view, headcount endpoint, tally-form UI |
| 5 | Branding | logo/favicon via profile strings + `window.APP_META`; profile-aware `pdfLogoPath()` |
| 6 | Frontend capability flags | `/api/bootstrap`; sidebar/router filter by capability |
| 7 | Entrypoint profile-awareness | boot seeding no longer loads WIS data for non-WIS profiles |
| 8 | Tests | mode equivalence, seed, module gate, member numbers, branding |
| 9 | Documentation | `DIOCESE_SETUP.md` (new runbook), `DEPLOYMENT.md` + `.env.example` updates, `CAPABILITY_INVENTORY.md` |
| 10 | Delivery | PR → merge to `main` → CI auto-publishes images |

### 5.4 Pre-merge validation

- `composer test` — full suite green.
- `npm run lint` — no frontend issues.
- `docker compose build && up` with `DIOCESE_PROFILE=mcgh`: verify no WIS
  data is seeded, headcount attendance works end-to-end, mcgh logo shows in
  UI and PDFs, default profile (`wis`) still behaves identically.

### 5.5 Documentation deliverables

| Doc | Purpose |
|---|---|
| `DIOCESE_SETUP.md` (new) | Diocese onboarding runbook: prerequisites, `.env`, pull & start, seed, member-data import, verify, update, troubleshooting |
| `DEPLOYMENT.md` (update) | Add `DIOCESE_PROFILE`, profile-aware seeding, profile reference |
| `.env.example` (update) | Add `DIOCESE_PROFILE` with comments |
| `CAPABILITY_INVENTORY.md` (new) | Registry of which profile has which flags/modules/strategies (prevents duplicate builds) |
