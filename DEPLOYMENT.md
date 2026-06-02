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
- 8 roles, 45 permissions
- 7 service types (Sunday Adult, Cell Meeting, etc.)
- 25 finance categories (Tithe, Offertory, Welfare, etc.)
- 1 super admin user (ADMIN_EMAIL + ADMIN_PASSWORD from .env)

ProductionSeeder is idempotent - safe to re-run on subsequent
deploys without creating duplicates or overwriting customizations.

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
