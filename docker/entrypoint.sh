#!/bin/sh
set -e

wait_for_db() {
    host="${DB_HOST:-postgres}"
    port="${DB_PORT:-5432}"
    echo "Waiting for database at ${host}:${port}..."
    until php -r "exit(@fsockopen(getenv('DB_HOST') ?: 'postgres', (int) (getenv('DB_PORT') ?: 5432)) ? 0 : 1);"; do
        sleep 2
    done
    echo "Database is reachable."
}

wait_for_db

# Auto-generate APP_KEY if not set (first boot without manual key:generate)
if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:" ]; then
    echo "APP_KEY is empty — generating a new key..."
    php artisan key:generate --force
fi

# DIOCESE_PROFILE is frozen at app boot (default: wis). Diocese-specific data
# must never leak across installs, so the WIS member CSV and the WIS church-data
# snapshot only run for the 'wis' profile.
profile="${DIOCESE_PROFILE:-wis}"

if [ "$1" = "php-fpm" ]; then
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
