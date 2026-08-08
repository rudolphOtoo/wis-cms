# WIS-CMS Production Deployment Guide

This guide covers the production deployment of WIS-CMS (Wesleyan
International Society Church Management System) for the Methodist
Church Ghana.

## Prerequisites

Before deploying, ensure the production server has:

- PHP 8.4+ with extensions: pdo_pgsql, mbstring, openssl, tokenizer, xml
- PostgreSQL 16+ database (provisioned, empty, accessible)
- Composer 2.x
- Node 20+ and npm
- A web server (nginx or Apache) configured to serve `public/`
- Cron access (for the `schedule:run` task)

## First-Time Deployment

### 1. Clone and install

    git clone https://github.com/rudolphOtoo/wis-cms.git
    cd wis-cms
    composer install --no-dev --optimize-autoloader
    npm ci
    npm run build

### 2. Configure `.env`

    cp .env.example .env
    php artisan key:generate

Then edit `.env` and set **at minimum**:

    APP_ENV=production
    APP_DEBUG=false
    APP_URL=https://your-domain.example

    # Active diocese profile — selects config/profiles/{slug}.php at deploy
    # time. Built-ins: 'wis' (default) and 'mcgh' (Methodist diocese).
    # One local install uses ONE profile; set it before the first boot.
    DIOCESE_PROFILE=wis

    # PostgreSQL connection
    DB_CONNECTION=pgsql
    DB_HOST=...
    DB_PORT=5432
    DB_DATABASE=wis_cms
    DB_USERNAME=...
    DB_PASSWORD=...

    # Church identity (used by BranchSeeder)
    CHURCH_NAME="Wesleyan International Society"
    CHURCH_LOCATION="Kumasi, Ghana"

    # Super admin - REQUIRED. Use a strong unique password.
    # The admin should change it on first login.
    ADMIN_EMAIL=admin@your-church.example
    ADMIN_PASSWORD=use-a-strong-password-here

    # mNotify SMS provider - required for production SMS delivery
    # Sender ID must be REGISTERED AND APPROVED by mNotify before
    # it will deliver. Max 11 alphanumeric characters.
    MNOTIFY_API_KEY=your-mnotify-api-key
    MNOTIFY_SENDER_ID=your-approved-sender-id

    # Mail - for non-SMS notifications (e.g. delivery failure alerts)
    MAIL_MAILER=smtp
    MAIL_HOST=...
    MAIL_PORT=587
    MAIL_USERNAME=...
    MAIL_PASSWORD=...
    MAIL_ENCRYPTION=tls
    MAIL_FROM_ADDRESS=no-reply@your-church.example
    MAIL_FROM_NAME="Wesleyan International Society"

    # Queue & cache - recommend redis in production
    QUEUE_CONNECTION=database
    CACHE_STORE=database
    SESSION_DRIVER=database

### 3. Run migrations and seed canonical data

    php artisan migrate --force
    php artisan db:seed --class=ProductionSeeder --force

The `--force` flag is required in production to bypass the
interactive confirmation prompt.

**What ProductionSeeder creates:**
- 1 branch (CHURCH_NAME from .env)
- Roles + permissions from the **active profile** (default WIS: 9 roles,
  55 permissions)
- Service types from the active profile (default WIS: 8, incl. Cell Meeting)
- Finance categories from the active profile (default WIS: 25)
- 1 super admin user (ADMIN_EMAIL + ADMIN_PASSWORD from .env)

ProductionSeeder is idempotent - safe to re-run on subsequent
deploys without creating duplicates or overwriting customizations.

### Profile-aware boot seeding

The Docker entrypoint only imports WIS-specific data (the `WIS_Ayeduase.csv`
member list and the legacy `database/church-data.json` snapshot) when
`DIOCESE_PROFILE=wis`. Under any other profile (e.g. `mcgh`) the boot runs
migrations + `ProductionSeeder` for that profile and imports a profile-declared
snapshot (`database/church-data-mcgh.json` if the diocese provides one) —
a diocese install never receives another church's membership data.

For a complete diocese onboarding runbook (Docker path), see
[`DIOCESE_SETUP.md`](./DIOCESE_SETUP.md).

### 4. Set up the scheduler

The app uses Laravel's scheduler for three recurring tasks:
- Daily birthday SMS (07:00)
- Weekly leader audit (Monday 08:00)
- Follow-up SMS dispatcher (every 15 minutes)

Add to the server's crontab (`crontab -e`):

    * * * * * cd /path/to/wis-cms && php artisan schedule:run >> /dev/null 2>&1

### 5. Set up a queue worker

The follow-up SMS feature dispatches jobs onto the queue. Run a
worker via supervisor at `/etc/supervisor/conf.d/wis-cms-worker.conf`:

    [program:wis-cms-worker]
    process_name=%(program_name)s_%(process_num)02d
    command=php /path/to/wis-cms/artisan queue:work --sleep=3 --tries=3 --max-time=3600
    autostart=true
    autorestart=true
    user=www-data
    numprocs=1
    redirect_stderr=true
    stdout_logfile=/var/log/wis-cms-worker.log
    stopwaitsecs=3600

Reload supervisor:

    sudo supervisorctl reread
    sudo supervisorctl update

### 6. Cache config and routes for production performance

    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

### 7. First login

Visit `https://your-domain.example/login` and sign in with the
ADMIN_EMAIL / ADMIN_PASSWORD set in `.env`.

**Change the admin password immediately on first login** via
Profile - Change Password.

## Subsequent Deployments

For routine updates after the first deploy:

    cd /path/to/wis-cms
    git pull origin main
    composer install --no-dev --optimize-autoloader
    npm ci && npm run build
    php artisan migrate --force
    php artisan db:seed --class=ProductionSeeder --force
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    sudo supervisorctl restart wis-cms-worker

The seed step is safe to skip if you know nothing changed in
canonical data, but running it is cheap and protects against
forgetting after a roles/permissions change ships.

## What's NOT in ProductionSeeder

The following seeders create development/demo data and must NEVER
be run in production:

- `CellSeeder` - creates fake cells with auto-assigned leaders
- `DemoDataSeeder` - fake members, attendance, transactions

Don't run `php artisan db:seed` (without `--class=ProductionSeeder`)
on a production database - that calls `DatabaseSeeder` which
includes the demo data.

## Health Checks

After deployment, verify:

Migrations all ran:

    php artisan migrate:status

Scheduler is registered:

    php artisan schedule:list

Queue is processing:

    ps aux | grep "queue:work"

Test follow-up scheduler (dry-run, safe):

    php artisan attendance:process-follow-ups --dry-run

## Troubleshooting

**"Admin login doesn't work"**

Check user count:

    php artisan tinker --execute='echo App\Models\User::count();'

If 0 users exist, ADMIN_EMAIL or ADMIN_PASSWORD were not set when
ProductionSeeder ran. Set them in .env and re-run:

    php artisan db:seed --class=ProductionSeeder --force

**"Follow-up SMS aren't being sent"**

1. Verify `php artisan schedule:list` shows the task
2. Verify the cron entry is in place
3. Verify queue worker is running: `ps aux | grep queue:work`
4. Check `storage/logs/laravel.log` for errors
5. Run `php artisan attendance:process-follow-ups` manually to test

**"Want to rotate the admin password"**

Use the UI (Profile - Change Password). Don't change ADMIN_PASSWORD
in .env and re-seed - the SuperAdminSeeder is idempotent and will
NOT update an existing user's password.

## Architecture: Multi-Tenant Branch Scoping

WIS-CMS is multi-tenant: every member, cell, transaction, message,
attendance session, and child belongs to a branch. The system uses
**defence-in-depth** to keep branch data separated: every read goes
through both an automatic global scope AND explicit controller
filters.

### The `BelongsToBranch` trait

Eight models use the trait (in `app/Models/Concerns/BelongsToBranch.php`):
Member, Visitor, Cell, Department, Transaction, Message,
AttendanceSession, Children.

The trait does two things:

1. **Global query scope** (`App\Models\Scopes\BranchScope`).
   When an authenticated user is present, every query against these
   models is automatically filtered to `branch_id = user->branch_id`.

2. **Auto-set `branch_id` on create**. New records get their
   `branch_id` filled from the authenticated user automatically.
   Controllers can still set it explicitly; the trait won't overwrite.

### When the trait does NOT apply

- **CLI / system jobs**: when no auth user is present
  (`Auth::user()` is null), the scope is skipped entirely. This is
  why scheduled commands like `birthdays:send`,
  `attendance:process-follow-ups`, and the FK audit can operate
  across all branches as intended.

- **The `User` model itself**: User provides the `branch_id` that
  the trait depends on. Applying the trait to User would create a
  chicken-and-egg loop on auth queries.

- **Bypass for admin tooling or tests**:

      Member::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
          ->where(...)
          ->get();

### Why controllers still filter manually

The 12 multi-tenant API controllers retain their explicit
`->where('branch_id', $user->branch_id)` filters even though the
trait makes them redundant. This is intentional:

- **Defence in depth**: a bug in the trait or scope won't leak data
  because the controller is also filtering.
- **Cost is essentially zero**: `branch_id` is indexed (added in
  17573c4); double-filtering on an indexed column is a no-op for
  the query planner.
- **Future cleanup is optional**: the manual filters can be removed
  in a follow-up commit once there are months of production
  confidence in the trait. No rush.

### Role-based visibility is separate

The trait handles the **branch** dimension only. Role-based "sees
all" logic (where `super_admin`, `pastor`, `secretary` see all
cells in their branch while `cell_leader` sees only their cell)
stays in controllers - it's orthogonal: it widens visibility
WITHIN a branch, not ACROSS branches.

If cross-branch admin ever becomes a feature, that's a separate
design (likely a nullable `users.branch_id` meaning "see all").

### Verifying integrity

After any model refactor, run:

    php artisan app:audit-fk-relationships

This audits that every FK column has a matching belongsTo
relationship method on its owning model. It detects both
class-defined methods and trait-provided ones (via
`method_exists()`). Exit code 1 if anything is missing; suitable
for CI failure gates.

It also runs weekly on the scheduler (Mondays 08:30 UTC).
