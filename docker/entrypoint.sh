#!/bin/sh
set -e

# Directory used to share state that MUST be identical across the app, queue
# and scheduler containers. It lives on a named volume that all three mount, so
# whichever container boots first creates the key and the others reuse it.
# Without this, each container would generate its own APP_KEY, and since
# SESSION_ENCRYPT=true and member attributes are encrypted at rest, sessions
# and stored ciphertext would become undecryptable the moment a container
# restarted — silent, permanent data loss.
RUNTIME_DIR="${WIS_RUNTIME_DIR:-/var/www/html/storage/docker-runtime}"
KEY_FILE="$RUNTIME_DIR/app_key"

wait_for_db() {
    host="${DB_HOST:-wis_cms_db}"
    port="${DB_PORT:-5432}"
    echo "Waiting for database at ${host}:${port}..."
    until php -r "exit(@fsockopen(getenv('DB_HOST') ?: 'wis_cms_db', (int) (getenv('DB_PORT') ?: 5432)) ? 0 : 1);"; do
        sleep 2
    done
    echo "Database is reachable."
}

wait_for_db

# Seed a missing .env from the template shipped in the image, so a fresh
# `docker compose up` on a machine with no .env at all still boots instead of
# aborting on a missing file.
if [ ! -f /var/www/html/.env ] && [ -f /var/www/html/.env.example ]; then
    if cp /var/www/html/.env.example /var/www/html/.env 2>/dev/null; then
        echo "Seeded /var/www/html/.env from .env.example (no .env was present)."
    else
        echo "WARNING: could not seed .env from .env.example; continuing with injected env vars only."
    fi
fi

mkdir -p "$RUNTIME_DIR" 2>/dev/null || true

# ---- Permissions ------------------------------------------------------------
# The image runs as www-data (non-root), so ownership is fixed at build time in
# the Dockerfile. Here we only (a) guarantee the writable directories Laravel
# needs actually exist, and (b) tighten the mode bits.
#
# These are best-effort: if a directory is owned by another user (e.g. a
# host-mounted volume) chmod will fail, and failing boot over a mode bit would
# be worse than a permissive directory. So every call is non-fatal.
ensure_writable_dir() {
    d="$1"
    if [ ! -d "$d" ] && mkdir -p "$d" 2>/dev/null; then
        :
    fi
    if [ -d "$d" ]; then
        chmod 0775 "$d" 2>/dev/null || true
    fi
}

# Laravel writes to all of these; a missing one is a hard 500 at runtime.
ensure_writable_dir /var/www/html/storage
ensure_writable_dir /var/www/html/storage/framework/cache
ensure_writable_dir /var/www/html/storage/framework/sessions
ensure_writable_dir /var/www/html/storage/framework/views
ensure_writable_dir /var/www/html/storage/logs
ensure_writable_dir /var/www/html/storage/app/public
ensure_writable_dir /var/www/html/storage/app/private
ensure_writable_dir /var/www/html/bootstrap/cache
ensure_writable_dir "$RUNTIME_DIR"

# .env holds DB and mNotify credentials, so it must not be group/world
# readable. 0600 also matches the mode used for the persisted APP_KEY.
if [ -f /var/www/html/.env ]; then
    chmod 0600 /var/www/html/.env 2>/dev/null || true
fi

# Resolve APP_KEY: reuse the shared key if one already exists, otherwise adopt
# a key injected by the operator, otherwise generate one and persist it for the
# sibling containers. The resolved value is exported so every subsequent artisan
# call and php-fpm worker sees it, and written into .env so the file on disk
# reflects the key actually in use.
resolved_key=""
if [ -f "$KEY_FILE" ]; then
    resolved_key=$(cat "$KEY_FILE" 2>/dev/null || echo "")
fi

if [ -z "$resolved_key" ]; then
    if [ -n "$APP_KEY" ] && [ "$APP_KEY" != "base64:" ]; then
        # Operator-supplied key wins, and becomes the shared key.
        resolved_key="$APP_KEY"
    else
        # Fall back to a key already present in an on-disk .env before
        # generating one. Compose normally injects .env into the environment
        # via env_file, but if that is disabled or the file is mounted
        # directly, honouring the stored key is what stops a boot from
        # silently rotating APP_KEY — which would make every session and all
        # at-rest ciphertext permanently undecryptable.
        if [ -f /var/www/html/.env ]; then
            file_key=$(sed -n 's/^APP_KEY=//p' /var/www/html/.env | head -n 1 | tr -d '"' | tr -d "'" | tr -d '\r')
            if [ -n "$file_key" ] && [ "$file_key" != "base64:" ]; then
                resolved_key="$file_key"
                echo "Adopting APP_KEY already present in .env (not rotating)."
            fi
        fi
    fi

    if [ -z "$resolved_key" ]; then
        resolved_key=$(php artisan key:generate --show --no-interaction)
        echo "APP_KEY was empty — generated a key and persisting it for all containers."
    fi

    if [ -n "$resolved_key" ] && printf '%s' "$resolved_key" > "$KEY_FILE" 2>/dev/null; then
        chmod 600 "$KEY_FILE" 2>/dev/null || true
    else
        echo "WARNING: could not persist APP_KEY to $KEY_FILE — each container will generate its own key."
    fi
fi

export APP_KEY="$resolved_key"

# Keep the on-disk .env in sync so the effective key is discoverable/backupable.
# The export above is what Laravel actually uses (Dotenv will not overwrite an
# existing environment variable), so a failure here is cosmetic only.
if [ -f /var/www/html/.env ] && [ -w /var/www/html/.env ]; then
    if grep -q '^APP_KEY=' /var/www/html/.env; then
        sed -i "s|^APP_KEY=.*|APP_KEY=${resolved_key}|" /var/www/html/.env 2>/dev/null || true
    else
        printf '\nAPP_KEY=%s\n' "$resolved_key" >> /var/www/html/.env 2>/dev/null || true
    fi
fi

# DIOCESE_PROFILE is frozen at app boot (default: wis). Diocese-specific data
# must never leak across installs, so the WIS member CSV and the WIS church-data
# snapshot only run for the 'wis' profile.
profile="${DIOCESE_PROFILE:-wis}"

if [ "$1" = "php-fpm" ]; then
    # Run migrations explicitly, before anything that touches the schema.
    # This was previously only reachable as a side effect of
    # `app:data-migrate --import`, which meant a diocese profile that skipped
    # the import path would silently boot against an unmigrated database.
    # Idempotent and safe on every boot; queue and scheduler both gate on this
    # container reporting healthy.
    echo "Running database migrations..."
    php artisan migrate --force --no-interaction

    case "$profile" in
        wis)
            php artisan app:data-migrate --import
            php artisan import:csv WIS_Ayeduase.csv
            ;;
        mcgh)
            # A diocese drops its own exported snapshot here (created via
            # `app:data-migrate --export`); absent on a fresh install, so
            # reference data is seeded and nothing WIS is imported.
            php artisan app:data-migrate --import --input=database/church-data-mcgh.json
            # Diocese member roster ships in the image (same headerless
            # format as the WIS CSV). Only imported when present — the
            # upsert pipeline is idempotent, so re-running on every boot
            # never duplicates.
            if [ -f MCC_Members.csv ]; then
                php artisan import:csv MCC_Members.csv
            fi
            ;;
        *)
            echo "Unknown DIOCESE_PROFILE '${profile}' — skipping diocese-specific imports." >&2
            php artisan app:data-migrate --import
            ;;
    esac

    php artisan config:cache
    php artisan route:cache
    php artisan view:cache

    # Cancel any remote SMS still queued on mNotify for automations that
    # were deactivated/deleted while the church PC was off. Runs BEFORE
    # the rolling sync so ghost reminders never survive a reboot.
    # Requires MNOTIFY_DRY_RUN=false — otherwise it no-ops safely.
    # Non-fatal: the daily cron and the admin UI observer retry this.
    echo "Cancelling remote SMS for deactivated automations..."
    php artisan sms:cancel-deactivated-reminders --force || echo "WARNING: deactivated-reminder cleanup skipped"

    # Pre-schedule dynamic SMS automations (birthdays, service reminders)
    # on mNotify's remote API so they deliver even when the church desktop
    # is powered off. Expires any past-due messages that were never sent.
    # Requires MNOTIFY_DRY_RUN=false in the deployment env — with live sends
    # configured these commands run unattended on every boot; otherwise they
    # no-op safely.
    # Non-fatal: if the API is unreachable or the key is missing the app
    # must still boot — the daily cron will retry on the next cycle.
    echo "Syncing rolling SMS automations..."
    php artisan sms:sync-rolling-automations || echo "WARNING: SMS sync skipped (will retry on next cron cycle)"

    # Drain any pushes that failed previously (network down at last boot).
    # Non-fatal: the every-5-minutes cron retries this continuously.
    php artisan sync:pending-schedules || echo "WARNING: pending schedule drain skipped"

    # Reconcile past-due SMS with mNotify's actual delivery report.
    # Determines whether messages were sent, failed (e.g. insufficient
    # credits), or are still pending — instead of naively marking them expired.
    echo "Reconciling remote SMS delivery statuses..."
    php artisan sms:reconcile-remote-statuses || echo "WARNING: remote status reconciliation skipped"

    # Cold-boot Paystack catch-up: pull every completed mobile money gift
    # that settled while the desktop PC was powered off, back fill payments
    # + finance ledger entries, and flush any sms_pending receipt SMS.
    # Idempotent (unique Paystack reference per payment) — safe to run on
    # every boot. Non-fatal: if Paystack or mNotify is unreachable the app
    # must still boot and the scheduler retries.
    echo "Reconciling offline Paystack payments..."
    php artisan payments:reconcile-paystack || echo "WARNING: Paystack reconciliation skipped (will retry on cron)"
fi

exec "$@"
