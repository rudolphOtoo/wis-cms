# Diocese Setup Guide (Docker path)

This runbook is for a **Methodist diocese** installing their own copy of
WIS-CMS with the `mcgh` profile. The same steps work for the default `wis`
profile — only `DIOCESE_PROFILE` and the reference-data values differ.

Everything ships inside the Docker images (profiles, branding, migrations,
frontend bundle), so there is **no code to fork** and no special build for a
new diocese.

---

## Quickstart — local spin-up in three steps

One install uses **one** diocese profile; pick it before first boot. The
whole stack (branding, seeders, attendance mode, member numbering) follows
from this single variable:

```bash
# 1. Copy the shipped env template (docker-compose.deploy.yml ships with
#    the repo — nothing to copy for Compose itself)
cp .env.example .env
```

Then edit `.env`:

```dotenv
# 2. Select ONE profile (uncomment exactly one line)
# DIOCESE_PROFILE=wis   # default — per-member register attendance
DIOCESE_PROFILE=mcgh   # Methodist diocese — headcount tally
```

```bash
# 3. Boot the stack
docker compose -f docker-compose.deploy.yml up -d
```

### How it works under the hood

On boot, `docker/entrypoint.sh` reads `DIOCESE_PROFILE` (default `wis`) and,
once Postgres is reachable:

1. runs **migrations** (`php artisan migrate --force`),
2. seeds **profile-specific reference data** for the active profile — roles
   + permissions, service types, finance categories, branch, super admin
   (via `ProductionSeeder`),
3. **skips the default WIS CSV imports for non-WIS profiles** — the
   `WIS_Ayeduase.csv` member list and the WIS church-data snapshot are only
   imported under `wis`. Under `mcgh` a profile-declared snapshot
   (`database/church-data-mcgh.json`) is used if present; otherwise
   reference data is seeded and nothing WIS is imported,
4. **imports the diocese member CSV** (`MCC_Members.csv`) when one ships in
   the image — the same idempotent `import:csv` pipeline WIS uses, so
   re-runs on later boots never duplicate members (see step 7),
5. caches config, routes, and views (`php artisan config:cache`).

Profile branding (logo, favicon, app name, report footer) is injected
server-side into the login page and app shell
(`resources/views/partials/app-meta.blade.php`), and capabilities are served
to the SPA through the bootstrap endpoint.

> `DIOCESE_PROFILE` is frozen when config is cached. Set it **before the
> first boot**; changing it later requires `config:clear && config:cache`
> (see Troubleshooting).

> **IMPORTANT:** the boot sequence only seeds profile-appropriate data.
> Under `mcgh` the WIS member CSV and the WIS church-data snapshot are
> **never** imported — a diocese install never receives another
> church's membership data.

---

## 1. Prerequisites

- Docker + Docker Compose v2 on the server.
- An empty PostgreSQL 16 database (or let the container create one).
- The diocese's member data as an Excel export (see step 6).

## 2. Configure `.env`

Copy the shipped example and set every value:

```bash
cp .env.example .env
```

Minimum required values:

```dotenv
# ── Profile ────────────────────────────────────────────────────────────
DIOCESE_PROFILE=mcgh

# ── App identity (drives branding + the branch name) ───────────────────
APP_NAME="Methodist Church Ghana"
CHURCH_NAME="Methodist Church Ghana"
CHURCH_LOCATION="Ghana"

# ── Super admin (created by ProductionSeeder) ──────────────────────────
ADMIN_EMAIL=admin@your-diocese.example
ADMIN_PASSWORD=use-a-strong-unique-password

# ── Database ───────────────────────────────────────────────────────────
POSTGRES_DB=wis_cms
POSTGRES_USER=wis_admin
POSTGRES_PASSWORD=<strong-db-password>

# ── App secrets ────────────────────────────────────────────────────────
APP_KEY=                       # php artisan key:generate
APP_URL=https://your-diocese.example
APP_ENV=production
APP_DEBUG=false
```

> `DIOCESE_PROFILE` is frozen when config is cached. Set it **before the
> first boot**; changing it later requires `config:clear && config:cache`
> (see Troubleshooting).

> **IMPORTANT:** the boot sequence only seeds profile-appropriate data.
> Under `mcgh` the WIS member CSV and the WIS church-data snapshot are
> **never** imported — a diocese install never receives another
> church's membership data.

## 3. Pull and start

```bash
docker compose -f docker-compose.deploy.yml pull
docker compose -f docker-compose.deploy.yml up -d
```

On first boot the `app` service:

1. waits for Postgres,
2. runs migrations,
3. seeds canonical reference data (roles, permissions, service types,
   finance categories, branch, super admin) for the **active** profile,
4. caches config, routes, and views.

If you prefer to run the seed explicitly (idempotent, safe to re-run):

```bash
docker compose -f docker-compose.deploy.yml exec app php artisan db:seed --class=ProductionSeeder --force
```

## 4. Verify the install

- `docker compose -f docker-compose.deploy.yml ps` — all services healthy.
- Log in at `https://your-diocese.example/login` with `ADMIN_EMAIL` /
  `ADMIN_PASSWORD` from `.env`.
- **Change the admin password on first login.**
- Branding: the login page, sidebar, and generated PDFs show the diocese
  logo and `APP_NAME`.
- Attendance: under `mcgh` the default mode is **headcount** — opening a
  session presents a Men / Women / Children door tally (no cells).

## 5. Update path

Pin the image tag so updates are controlled:

```bash
export IMAGE_TAG=<version-or-sha>        # default: latest
docker compose -f docker-compose.deploy.yml pull
docker compose -f docker-compose.deploy.yml up -d
```

Migrations run automatically on boot. `ProductionSeeder` is re-run each
boot and is idempotent — it never duplicates or overwrites your data.

## 6. Import the diocese's member data

The import pipeline reads a **WIS church Excel export** (single sheet with
columns `last_name, first_name, dob, gender, phone`). Always dry-run first:

```bash
# 1. Copy the export into the container
docker compose -f docker-compose.deploy.yml cp members.xlsx app:/tmp/members.xlsx

# 2. Preview (no writes)
docker compose -f docker-compose.deploy.yml exec app \
  php artisan import:church-data /tmp/members.xlsx --dry-run

# 3. Real import (creates members + children + member numbers)
docker compose -f docker-compose.deploy.yml exec app \
  php artisan import:church-data /tmp/members.xlsx
```

Member numbers follow the diocese's scheme (`MCC/{year}/{00001}` for the
`mcgh` profile) automatically.

## 7. Shipping member data inside the image (WIS-style)

Instead of the diocese importing after boot (step 6), the maintainer can
bake the diocese's members into the image so they are present **automatically
on first boot** — exactly how WIS ships `WIS_Ayeduase.csv`.

1. Convert the diocese's member export to the same headerless CSV format:
   `last_name, first_name, dob, gender, phone` (dates `DD-MM-YYYY`, gender
   `Male`/`Female`, one Ghana phone per row). `dob` and `gender` are
   **optional** — leave them empty when the diocese roster does not record
   them (unknown gender is stored as `NULL`). `phone` may also be empty;
   such members are matched by `(branch, first, last)` on re-import instead.
2. Save it at the repo root as **`MCC_Members.csv`** and commit it.
   (`docker/entrypoint.sh` only imports it when present, so images without
   the file boot normally.)
3. Rebuild and push the image (CI `docker-publish` does this on `main`).
4. Diocese pulls and runs `up -d` — on the first boot the members are
   imported with `MCC/{year}/{00001}` numbers. The `import:csv` upsert is
   idempotent, so every subsequent boot re-runs it without duplicating.

Always dry-run before committing the file:

```bash
php artisan import:csv MCC_Members.csv --dry-run
```

Confirm the data landed after the first boot (member count + numbering):

```bash
docker compose -f docker-compose.deploy.yml exec app \
  php artisan tinker --execute="echo App\\Models\\Member::count().' members — '.App\\Models\\Member::min('member_number').' .. '.App\\Models\\Member::max('member_number');"
# e.g. → 369 members — MCC/2026/00001 .. MCC/2026/00369
```

> **Note:** the CSV (and therefore member PII) ships inside the image, same
> as `WIS_Ayeduase.csv` today. Ensure the GHCR package is **private** before
> pushing a diocese's real member data.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Profile changes don't take effect | The profile is cached. Run `php artisan config:clear` then `php artisan config:cache`, or re-run the entrypoint (`docker compose -f docker-compose.deploy.yml restart app`). |
| Permission errors after an upgrade | `docker compose ... exec app php artisan permission:cache-reset` |
| Forgotten admin password | Reset in the DB, or temporarily set a new `ADMIN_PASSWORD` in `.env` and re-run `SuperAdminSeeder`. |
| Boot hangs at "Waiting for database" | Check `DB_HOST`/`DB_PORT` in `.env`; ensure the `postgres` service is healthy. |
| Old WIS data appears | Not possible from this flow — see step 2 note. If it did happen, the data came from an imported snapshot and must be removed manually. |
