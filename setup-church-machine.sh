#!/usr/bin/env bash
set -euo pipefail

# ─────────────────────────────────────────────────────────────
#  WIS-CMS Church Machine Setup
#  Run this on the deployed server (docker-compose.deploy.yml)
# ─────────────────────────────────────────────────────────────

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()  { echo -e "${GREEN} ✓${NC} $1"; }
warn()  { echo -e "${YELLOW} ⚠${NC} $1"; }
err()   { echo -e "${RED} ✗${NC} $1"; }

# ── Prerequisites ───────────────────────────────────────────
echo -e "\n${YELLOW}WIS-CMS Church Machine Setup${NC}\n"

# Check Docker
if ! docker ps &>/dev/null; then
    err "Docker is not running. Start it first."
    exit 1
fi

# Check containers are up
for svc in wis_cms_db wis_cms_app; do
    if ! docker ps --format '{{.Names}}' | grep -q "$svc"; then
        err "Container '$svc' is not running. Run 'docker compose -f docker-compose.deploy.yml up -d' first."
        exit 1
    fi
done
info "All containers are running"

# ── 1. Production .env ──────────────────────────────────────
echo -e "\n${YELLOW}── Step 1: Production .env${NC}"

ENV_FILE=".env"
if [ ! -f "$ENV_FILE" ]; then
    err ".env file not found in current directory"
    exit 1
fi

# Source current .env
set -a; source "$ENV_FILE"; set +a

UPDATE_ENV=false

if [ "$APP_ENV" != "production" ]; then
    warn "APP_ENV is '$APP_ENV' — should be 'production'"
    read -p "Set APP_ENV=production? [Y/n] " yn; yn=${yn:-Y}
    if [[ "$yn" =~ ^[Yy] ]]; then
        sed -i 's/^APP_ENV=.*/APP_ENV=production/' "$ENV_FILE"
        info "APP_ENV set to production"
        UPDATE_ENV=true
    fi
fi

if [ "$APP_DEBUG" != "false" ]; then
    warn "APP_DEBUG is '$APP_DEBUG' — should be 'false' (prevents stack trace leaks)"
    read -p "Set APP_DEBUG=false? [Y/n] " yn; yn=${yn:-Y}
    if [[ "$yn" =~ ^[Yy] ]]; then
        sed -i 's/^APP_DEBUG=.*/APP_DEBUG=false/' "$ENV_FILE"
        info "APP_DEBUG set to false"
        UPDATE_ENV=true
    fi
fi

if [ -z "${ADMIN_EMAIL:-}" ]; then
    warn "ADMIN_EMAIL is blank — seeder uses 'admin@wis-cms.local' (dev default)"
    read -p "Enter admin email for production: " admin_email
    if [ -n "$admin_email" ]; then
        sed -i "s/^ADMIN_EMAIL=.*/ADMIN_EMAIL=$admin_email/" "$ENV_FILE"
        info "ADMIN_EMAIL set to $admin_email"
        UPDATE_ENV=true
    fi
fi

if [ -z "${ADMIN_PASSWORD:-}" ]; then
    warn "ADMIN_PASSWORD is blank — seeder uses 'Admin@12345' (dev default)"
    read -s -p "Enter admin password (min 8 chars): " admin_pass; echo
    if [ ${#admin_pass} -ge 8 ]; then
        sed -i "s/^ADMIN_PASSWORD=.*/ADMIN_PASSWORD=$admin_pass/" "$ENV_FILE"
        info "ADMIN_PASSWORD set"
        UPDATE_ENV=true
    else
        warn "Password too short — skipped. Set it manually in .env"
    fi
fi

if [ -z "${APP_URL:-}" ] || [ "$APP_URL" = "http://localhost" ] || [ "$APP_URL" = "http://127.0.0.1:8000" ]; then
    warn "APP_URL is '$APP_URL'"
    read -p "Enter the actual URL (e.g. https://wis.example.com) [Enter to skip]: " app_url
    if [ -n "$app_url" ]; then
        sed -i "s|^APP_URL=.*|APP_URL=$app_url|" "$ENV_FILE"
        info "APP_URL set to $app_url"
        UPDATE_ENV=true
    fi
fi

if [ "$UPDATE_ENV" = true ]; then
    # Reload env and restart app container
    set -a; source "$ENV_FILE"; set +a
    docker restart wis_cms_app wis_cms_queue wis_cms_scheduler
    info "Containers restarted with new .env"
fi

# ── 2. Admin password reset ─────────────────────────────────
echo -e "\n${YELLOW}── Step 2: Reset admin password${NC}"
if [ -n "${ADMIN_EMAIL:-}" ] && [ -n "${ADMIN_PASSWORD:-}" ]; then
    read -p "Reset admin password in the database to match .env? [Y/n] " yn; yn=${yn:-Y}
    if [[ "$yn" =~ ^[Yy] ]]; then
        docker exec wis_cms_app php artisan tinker --execute="
            \$u = \App\Models\User::where('email', '$ADMIN_EMAIL')->first();
            if (\$u) {
                \$u->password = bcrypt('$ADMIN_PASSWORD');
                \$u->save();
                echo 'Password updated for ' . \$u->email . PHP_EOL;
            } else {
                echo 'User with email $ADMIN_EMAIL not found. Create via seeder or admin panel.' . PHP_EOL;
            }
        "
        info "Admin password reset"
    fi
else
    warn "Skip password reset — ADMIN_EMAIL or ADMIN_PASSWORD not set"
fi

# ── 3. Import member data ───────────────────────────────────
echo -e "\n${YELLOW}── Step 3: Import member & children data${NC}"

SQL_FILE="wis_data.sql"
if [ -f "$SQL_FILE" ]; then
    read -p "Import members and children from $SQL_FILE? [y/N] " yn
    if [[ "$yn" =~ ^[Yy] ]]; then
        BRANCH_UUID=$(docker exec wis_cms_db psql -U wis_admin -d wis_cms -t -c \
            "SELECT id FROM branches WHERE name = '${CHURCH_NAME:-Wesleyan International Society}'" | xargs)
        
        if [ -z "$BRANCH_UUID" ]; then
            err "Branch '${CHURCH_NAME}' not found in database"
            exit 1
        fi
        
        info "Branch UUID: $BRANCH_UUID"
        
        # Replace placeholder and import
        sed -i "s/__BRANCH_UUID__/$BRANCH_UUID/g" "$SQL_FILE"
        
        if docker exec -i wis_cms_db psql -U wis_admin -d wis_cms < "$SQL_FILE"; then
            info "Data imported successfully"
            # Restore placeholder for future use
            sed -i "s/$BRANCH_UUID/__BRANCH_UUID__/g" "$SQL_FILE"
        else
            err "Import failed"
            exit 1
        fi
    fi
else
    warn "wis_data.sql not found. Copy it to this directory first (scp wis_data.sql user@server:/path/)"
fi

# ── Summary ─────────────────────────────────────────────────
echo -e "\n${GREEN}── Setup complete ──${NC}"
echo "  APP_ENV:      $(grep ^APP_ENV= .env | cut -d= -f2)"
echo "  APP_DEBUG:    $(grep ^APP_DEBUG= .env | cut -d= -f2)"
echo "  APP_URL:      $(grep ^APP_URL= .env | cut -d= -f2)"
echo "  ADMIN_EMAIL:  $(grep ^ADMIN_EMAIL= .env | cut -d= -f2)"
echo ""
echo "Login at http://localhost:8000 (or your APP_URL)"
