# WIS-CMS

**Wesleyan International Society Church Management System** — a full-stack web application for The Methodist Church Ghana — Wesleyan International Society.

WIS-CMS replaces paper-based church administration with a secure, branch-scoped platform for member records, visitors, departments, attendance, finance, communications, cells, and children's ministry.

[![CI](https://github.com/rudolphOtoo/wis-cms/actions/workflows/ci.yml/badge.svg)](https://github.com/rudolphOtoo/wis-cms/actions/workflows/ci.yml)
![Laravel](https://img.shields.io/badge/Laravel-13-red?style=flat-square&logo=laravel)
![React](https://img.shields.io/badge/React-19-blue?style=flat-square&logo=react)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-blue?style=flat-square&logo=postgresql)
![License](https://img.shields.io/badge/license-MIT-green?style=flat-square)

---

## About

Built as a pro bono project for a congregation of approximately 800–1,000 members. The system is designed for a single branch today, with UUID-based models and branch scoping on the API to support growth.

---

## Features

### Implemented

| Module | Description |
|--------|-------------|
| **Authentication** | Login, logout, profile (`/me`), password change via Laravel Sanctum |
| **Members** | CRUD, search, filters, pagination, stats, soft deletes, auto-generated member numbers (`WIS-YYYY-####`) |
| **Visitors** | CRUD, search, filters, stats |
| **Departments** | CRUD, leader assignment, attach/detach members |
| **Cells** | CRUD, member management, attendance (recorded via attendance sessions), cell leader role with scoped access |
| **Attendance** | Session creation, register taking, per-member history with attendance rate trends |
| **Finance** | CRUD with income/expense categories, searchable transaction log |
| **Children** | CRUD, stats, linked departures follow-up |
| **Communication** | Compose, broadcast, per-member message history with delivery tracking |
| **Dashboard & reports** | Live stats (member/visitor/child counts, growth, attendance trends), PDF export, CSV download |
| **Users & roles** | Seven roles seeded via Spatie Permission, enforced by middleware and FormRequest `authorize()` |
| **Activity log** | Audited writes on key actions (Spatie Activity Log) |

---

## Tech stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 13, PHP 8.3+ |
| Frontend | React 19, React Router 7, Vite 8, Tailwind CSS v4 |
| Database | PostgreSQL 16 (recommended) or SQLite for quick trials |
| Auth | Laravel Sanctum (Bearer token) |
| RBAC | [spatie/laravel-permission](https://github.com/spatie/laravel-permission) |
| Audit | [spatie/laravel-activitylog](https://github.com/spatie/laravel-activitylog) |
| PDF / Excel | DomPDF, OpenSpout (report download menu wired for attendance + members) |
| Containers | Docker Compose (PostgreSQL) |

---

## Prerequisites

- PHP 8.3+ with extensions required by Laravel (`mbstring`, `pdo`, `openssl`, etc.)
- [Composer](https://getcomposer.org/)
- [Node.js](https://nodejs.org/) 18+
- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (for PostgreSQL)

---

## Local development

### 1. Clone and install

```bash
git clone https://github.com/yourusername/wis-cms.git
cd wis-cms

composer install
npm install
```

### 2. Environment

```bash
cp .env.example .env
php artisan key:generate
```

Configure the database. **PostgreSQL** (matches `docker-compose.yml`):

```env
APP_NAME="WIS-CMS"
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=wis_cms
DB_USERNAME=wis_admin
DB_PASSWORD=wis_secret_2024
```

Start PostgreSQL:

```bash
docker compose up -d
```

For a quick trial without Docker, you can use SQLite (`DB_CONNECTION=sqlite` and `DB_DATABASE` pointing at `database/database.sqlite`).

### 3. Migrate and seed

```bash
php artisan migrate --seed
```

Seeders create:

- Default branch (Wesleyan International Society, Kumasi)
- Roles and permissions (seven roles)
- Service types and finance categories
- Super admin user (see below)

### 4. Run the app

**Option A — all services (recommended)**

```bash
composer dev
```

Runs Laravel (`:8000`), queue worker, log tail (Pail), and Vite (`:3000`) together.

**Option B — separate terminals**

```bash
php artisan serve          # http://127.0.0.1:8000
npm run dev                # Vite dev server on :3000
```

Open the app at **http://127.0.0.1:8000**. The React SPA is served through Laravel; API requests go to `/api`.

### One-command setup

After cloning and copying `.env` (with database configured):

```bash
composer setup
```

Runs `composer install`, generates the app key, migrates, `npm install`, and builds frontend assets.

---

## Running with Docker

The whole stack (Postgres, the Laravel app, nginx, a queue worker, and a scheduler loop) can run in containers — no PHP, Composer, or Node needed on the host.

```bash
cp .env.example .env
php artisan key:generate   # requires PHP locally just for this one command, or generate manually and paste into .env

docker compose build
docker compose up -d
```

Then run migrations happen automatically on startup (`docker/entrypoint.sh` runs `migrate --force` before `php-fpm` starts). Seed on first run:

```bash
docker compose exec app php artisan db:seed --class=ProductionSeeder --force
```

The app is available at `http://localhost:8000`.

Services:

| Service | Role |
|---------|------|
| `postgres` | PostgreSQL 16, data persisted in the `wis_postgres_data` volume |
| `app` | PHP-FPM; runs migrations + config/route/view cache on boot |
| `webserver` | nginx, serves `public/` and proxies `.php` requests to `app` |
| `queue` | `php artisan queue:work`, restarts hourly (matches `DEPLOYMENT.md` supervisor config) |
| `scheduler` | Polls `php artisan schedule:run` every 60 seconds |

`app`, `queue`, and `scheduler` share the same built image (`wis-cms-app`) so `docker compose build` only builds it once. Rebuild after dependency or code changes:

```bash
docker compose build && docker compose up -d
```

### Publishing images (no source access needed on the target machine)

The `app` and `webserver` images are self-contained (code and built frontend assets are baked in), so they can be pushed to a registry and run elsewhere without cloning this repo.

**CI (recommended):** `.github/workflows/docker-publish.yml` builds and pushes both images to GHCR on every push to `main` (tagged `latest` + short SHA) and on `v*` tags (tagged with the semver). Uses the built-in `GITHUB_TOKEN`, no extra secrets needed.

**Manual push:**

```bash
docker/build-and-push.sh          # tags as :latest
docker/build-and-push.sh v1.2.0   # or pass an explicit tag
```

Pushes `ghcr.io/rudolphotoo/wis-cms-app` and `ghcr.io/rudolphotoo/wis-cms-webserver`. Requires `docker login ghcr.io` with a PAT that has `write:packages`.

**Running on another machine** (only `docker-compose.deploy.yml` and `.env` are needed — no repo checkout):

```bash
cp .env.example .env    # fill in real secrets
docker compose -f docker-compose.deploy.yml pull
docker compose -f docker-compose.deploy.yml up -d
```

If the GHCR packages are private, that machine also needs `docker login ghcr.io` with a PAT that has `read:packages`. Set `IMAGE_TAG` in `.env` to pin a specific version instead of `latest`.

---

## Default login

After seeding, sign in with:

| Field | Value |
|-------|-------|
| Email | `admin@wis-cms.local` |
| Password | `Admin@12345` |

Change this password before any production deployment.

---

## User roles

| Role | Slug | Typical access |
|------|------|----------------|
| Super Admin | `super_admin` | Full system access |
| Pastor | `pastor` | Read-mostly across modules, exports |
| Secretary | `secretary` | Members, visitors, attendance, departments, messaging |
| Finance Officer | `finance_officer` | Finance transactions and reports |
| Department Leader | `department_leader` | Department membership and attendance |
| Cell Leader | `cell_leader` | Own cell's members and attendance (sidebar + queries auto-scoped) |
| Usher | `usher` | Attendance capture |

Permissions are defined in `database/seeders/RolesAndPermissionsSeeder.php`.

---

## Project structure

```
wis-cms/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/    # REST API controllers
│   │   ├── Requests/           # Form request validation
│   │   └── Resources/          # JSON API resources
│   └── Models/                 # Eloquent models (UUID primary keys)
├── database/
│   ├── migrations/             # Schema
│   └── seeders/                # Branches, roles, defaults
├── resources/
│   ├── js/
│   │   ├── api/                # Axios API clients (error toast interceptor)
│   │   ├── components/
│   │   │   ├── layout/         # App shell, sidebar, top bar
│   │   │   ├── ConfirmDialog.jsx   # Accessible confirmation modal
│   │   │   └── ErrorBoundary.jsx   # React error boundary
│   │   ├── hooks/              # useConfirm hook, shared state
│   │   ├── context/            # Auth context
│   │   ├── pages/              # Feature pages
│   │   └── routes/             # React Router
│   └── css/app.css             # Tailwind theme (navy / gold)
├── routes/
│   ├── api.php                 # JSON API routes
│   └── web.php                 # SPA catch-all
└── docker-compose.yml          # PostgreSQL 16
```

---

## API overview

Base URL: `/api`

### Public

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/auth/login` | Obtain Bearer token |

### Authenticated (`Authorization: Bearer {token}`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/auth/logout` | Revoke current token |
| `GET` | `/auth/me` | Current user, roles, permissions |
| `POST` | `/auth/change-password` | Update password |
| `GET` | `/dashboard` | Dashboard stats (member/visitor/child counts, growth, attendance rate) |
| `*` | `/members`, `/members/stats` | Member CRUD and statistics |
| `*` | `/visitors`, `/visitors/stats` | Visitor CRUD and statistics |
| `*` | `/children`, `/children/stats` | Children CRUD and statistics |
| `*` | `/departments`, `/departments/stats` | Department CRUD and statistics |
| `GET/POST/DELETE` | `/departments/{id}/members` | Manage department members |
| `*` | `/cells`, `/cells/stats` | Cell CRUD and statistics |
| `GET/POST` | `/cells/{id}/members` | Manage cell members |
| `POST` | `/cells/{id}/message` | Send SMS to cell members |
| `*` | `/attendance/sessions` | Attendance session CRUD |
| `GET/POST` | `/attendance/sessions/{id}/records` | Register records |
| `GET` | `/attendance/history/{member}` | Per-member attendance history |
| `*` | `/finance/transactions`, `/finance/stats` | Finance CRUD and statistics |
| `*` | `/communication/messages`, `/communication/stats` | Message CRUD and statistics |
| `GET` | `/portal/attendance` | Current member's recent attendance |
| `GET/POST/PUT/DELETE` | `/users` | User management (admin) |
| `POST` | `/users/{id}/link-member` | Link user to member record |
| `POST` | `/users/{id}/create-and-link-member` | Create + link member in one step |
| `POST` | `/users/{id}/unlink-member` | Sever user–member link |
| `GET` | `/reports/attendance-trends` | Attendance trend data (grouped by week/month/quarter) |
| `GET` | `/reports/export` | PDF/Excel export |

List endpoints support query parameters such as `search`, `status`, `gender`, `page`, and `per_page` where applicable. All data is scoped to the authenticated user's `branch_id`.

---

## Frontend routes

| Path | Page |
|------|------|
| `/login` | Sign in |
| `/dashboard` | Dashboard |
| `/members`, `/members/new`, `/members/:id/edit` | Members |
| `/visitors`, `/visitors/new`, `/visitors/:id/edit` | Visitors |
| `/children`, `/children/new`, `/children/:id/edit` | Children |
| `/departments`, `/departments/new`, `/departments/:id`, `/departments/:id/edit` | Departments |
| `/cells`, `/cells/new`, `/cells/:id`, `/cells/:id/edit` | Cells |
| `/attendance`, `/attendance/new`, `/attendance/sessions/:id/register` | Attendance |
| `/finance`, `/finance/new` | Finance |
| `/communication`, `/communication/compose` | Communication |
| `/reminders` | Reminders / departures follow-up |
| `/birthdays` | Upcoming birthdays |
| `/settings` | Follow-up configuration |
| `/submissions` | Recent submissions |
| `/admin/users`, `/admin/users/new`, `/admin/users/:id/edit` | User management |

---

## Testing

The project uses **Pest** for both feature and unit tests.

```bash
composer test
# or
php artisan test
```

Tests cover authentication, member/visitor/child CRUD, attendance recording, cell management, role-based access control, branch scoping, and report generation.

---

## Production build

```bash
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Serve the application with a proper web server (nginx, Apache, or Laravel Forge) pointing the document root at `public/`.

---

## Environment variables (reference)

| Variable | Purpose |
|----------|---------|
| `APP_URL` | Application URL (used for links and Sanctum) |
| `DB_*` | Database connection (use `pgsql` in production) |
| `VITE_APP_NAME` | Frontend app title |
| `APP_DEBUG` | Keep `false` in production (disables verbose error pages) |
| `SESSION_ENCRYPT` | Set `true` in production (encrypts session data at rest) |
| `SESSION_SECURE_COOKIE` | Set `true` in production (HTTPS-only cookies) |

---

## Contributing

1. Create a feature branch from `main`.
2. Follow existing patterns: Form Requests, API Resources (`UserResource`, `MemberResource`), the `BelongsToBranch` trait for branch scoping, and `activity()` logging on mutating actions.
3. Run code style checks before committing:
   ```bash
   ./vendor/bin/pint          # Laravel Pint (PHP)
   npm run lint               # ESLint (JS/JSX)
   ```
4. Run `php artisan test` and ensure the app loads at `http://127.0.0.1:8000` before opening a pull request.

---

## License

MIT License. See the repository license file for details.

---

## Acknowledgements

Built for **The Methodist Church Ghana — Wesleyan International Society**.
