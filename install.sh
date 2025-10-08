#!/usr/bin/env bash
set -Eeuo pipefail

# ========== Helpers ==========
box() {
  local text="$1"
  local pad="  "
  local width=${#text}
  local line="+$(printf '%*s' $((width+${#pad}*2)) '' | tr ' ' '-')+"
  echo "$line"
  echo "|${pad}${text}${pad}|"
  echo "$line"
}
step()  { echo -e "\n[•] $*"; }
ok()    { echo -e "[OK] $*"; }
warn()  { echo -e "[!] $*"; }
fail()  { echo -e "[X] $*" >&2; exit 1; }

need()  { command -v "$1" >/dev/null 2>&1 || fail "Command not found: $1"; }

ask() { # ask "Prompt" "default"
  local prompt="$1"; local def="${2-}"; local ans
  if [ -n "$def" ]; then
    read -r -p "$prompt [$def]: " ans || true
    echo "${ans:-$def}"
  else
    read -r -p "$prompt: " ans || true
    echo "$ans"
  fi
}

ask_secret() {
  local prompt="$1"; local ans
  read -r -s -p "$prompt " ans || true
  printf '\n' >&2
  printf '%s' "$ans"
}


escape_sed() { printf '%s' "$1" | sed -e 's/[\/&]/\\&/g'; }

set_env() { # set_env KEY VAL  (replace or append in .env)
  local key="$1"; local val="$2"; local file=".env"
  local esc_val; esc_val="$(escape_sed "$val")"
  if grep -qE "^[#[:space:]]*${key}=" "$file" 2>/dev/null; then
    sed -i -E "s|^[#[:space:]]*${key}=.*|${key}=${esc_val}|" "$file"
  else
    printf "%s=%s\n" "$key" "$val" >> "$file"
  fi
}

ver_ge() { # ver_ge HAVE NEED  -> returns 0 if HAVE >= NEED (using sort -V)
  local have="$1" needv="$2"
  [ "$(printf '%s\n%s\n' "$needv" "$have" | sort -V | head -n1)" = "$needv" ]
}

ensure_composer_min() {
  # Ensure Composer >= 2.2.0
  local MIN="2.2.0"
  export COMPOSER_ALLOW_SUPERUSER=1

  COMPOSER_BIN="$(command -v composer || true)"

  get_ver() {
    local bin="$1"
    "$bin" --version 2>&1 | head -n1 | grep -Eo '[0-9]+\.[0-9]+\.[0-9]+' || true
  }

  install_fresh() {
    step "Installing Composer 2 to /usr/local/bin"
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php composer-setup.php --2 --install-dir=/usr/local/bin --filename=composer
    rm -f composer-setup.php
    COMPOSER_BIN="/usr/local/bin/composer"
    command -v composer >/dev/null 2>&1 || fail "Composer installation failed."
    ok "Composer installed: $("$COMPOSER_BIN" -V 2>&1 | head -n1)"
  }

  if [ -z "${COMPOSER_BIN:-}" ]; then
    install_fresh
  else
    local HAVE
    HAVE="$(get_ver "$COMPOSER_BIN")"

    if [ -z "$HAVE" ]; then
      warn "Could not detect Composer version; trying self-update..."
      if "$COMPOSER_BIN" self-update --2 >/dev/null 2>&1 || "$COMPOSER_BIN" self-update 2.7.7 >/dev/null 2>&1; then
        HAVE="$(get_ver "$COMPOSER_BIN")"
      fi
    fi

    if [ -z "$HAVE" ]; then
      warn "Still cannot detect Composer version; installing fresh copy."
      install_fresh
    else
      # Compare with PHP's version_compare (robust)
      if ! php -r "exit(version_compare('$HAVE', '$MIN', '>=') ? 0 : 1);"; then
        step "Upgrading Composer (current: $HAVE, required: >= $MIN)"
        if "$COMPOSER_BIN" self-update --2 >/dev/null 2>&1 || "$COMPOSER_BIN" self-update 2.7.7 >/dev/null 2>&1; then
          ok "Composer self-updated: $("$COMPOSER_BIN" -V 2>&1 | head -n1)"
        else
          warn "Composer self-update failed; installing fresh copy."
          install_fresh
        fi
      else
        ok "Composer version OK: $("$COMPOSER_BIN" -V 2>&1 | head -n1)"
      fi
    fi
  fi

  # Export path for later composer calls
  export COMPOSER_BIN="${COMPOSER_BIN:-/usr/local/bin/composer}"
}

# ========== Header & Preflight ==========
box "Panel Installer"
need php
need sed
# composer check/upgrade/install
ensure_composer_min
: "${COMPOSER_BIN:=composer}"

PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$PROJECT_DIR"

step "Preparing .env"
if [ ! -f ".env" ]; then
  if [ -f ".env.example" ]; then
    cp .env.example .env
    ok ".env created from .env.example"
  else
    touch .env
    warn ".env.example not found; created empty .env"
  fi
else
  ok ".env exists"
fi

# ========== Inputs ==========
box "Inputs"

# 1) APP URL
APP_URL="$(ask 'APP URL (example: https://example.com)')"
if [ -z "$APP_URL" ]; then
  fail "APP URL is required."
fi

# 2) Database
DB_HOST="$(ask 'DB HOST' '127.0.0.1')"
DB_PORT="$(ask 'DB PORT' '3306')"

DB_DATABASE=""
while [ -z "$DB_DATABASE" ]; do
  DB_DATABASE="$(ask 'DB DATABASE')"
  [ -z "$DB_DATABASE" ] && warn "DB DATABASE is required."
done

DB_USERNAME=""
while [ -z "$DB_USERNAME" ]; do
  DB_USERNAME="$(ask 'DB USERNAME')"
  [ -z "$DB_USERNAME" ] && warn "DB USERNAME is required."
done

DB_PASSWORD="$(ask 'DB PASSWORD')"

# 3) Admin
ADMIN_EMAIL=""
while [ -z "$ADMIN_EMAIL" ]; do
  ADMIN_EMAIL="$(ask 'ADMIN EMAIL (example: admin@example.com)')"
  [ -z "$ADMIN_EMAIL" ] && warn "ADMIN EMAIL is required."
done

ADMIN_PASSWORD=""
while [ -z "$ADMIN_PASSWORD" ]; do
  ADMIN_PASSWORD="$(ask_secret 'ADMIN PASSWORD:')"
  [ -z "$ADMIN_PASSWORD" ] && warn "ADMIN PASSWORD is required."
done

ADMIN_PANEL_CODE="$(ask 'ADMIN PANEL CODE (accounts are created with this code) ')"

# ========== Write .env ==========
step "Writing .env values"

set_env APP_ENV "production"
set_env APP_DEBUG "false"
set_env APP_URL "$APP_URL"

set_env DB_CONNECTION "mysql"
set_env DB_HOST "$DB_HOST"
set_env DB_PORT "$DB_PORT"
set_env DB_DATABASE "$DB_DATABASE"
set_env DB_USERNAME "$DB_USERNAME"
set_env DB_PASSWORD "$DB_PASSWORD"

# Same-origin friendly defaults (no prompts)
if [[ "$APP_URL" == https://* ]]; then
  set_env SESSION_SECURE_COOKIE "true"
else
  set_env SESSION_SECURE_COOKIE "false"
fi
set_env SESSION_SAME_SITE "lax"
set_env SESSION_DOMAIN ""
set_env SANCTUM_STATEFUL_DOMAINS ""
set_env CORS_ALLOWED_ORIGINS ""
set_env VITE_API_BASE "/api"

ok ".env updated"

# ========== Backend setup ==========
step "Installing PHP dependencies"
"$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction

step "Generating app key"
php artisan key:generate --ansi

step "Running migrations (forced)"
php artisan migrate --force

step "Linking storage"
php artisan storage:link || true

step "Fixing permissions (best-effort)"
chown -R www:www storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

step "Optimizing (config/events/routes; skip views if missing)"
php artisan config:cache
php artisan event:cache
php artisan route:cache
if [ -d "resources/views" ]; then
  php artisan view:cache
else
  warn "Skipping view cache (resources/views not found)"
fi


# ========== Create/Update Admin ==========
step "Creating/Updating admin user"

export __ADMIN_EMAIL__="$ADMIN_EMAIL"
export __ADMIN_PASS__="$ADMIN_PASSWORD"
export __ADMIN_CODE__="$ADMIN_PANEL_CODE"

php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$email = getenv("__ADMIN_EMAIL__");
$pass  = getenv("__ADMIN_PASS__");
$code  = getenv("__ADMIN_CODE__");

if (!$email || !$pass) { fwrite(STDERR, "Missing admin credentials\n"); exit(1); }

$now = date("Y-m-d H:i:s");
$hash = password_hash($pass, PASSWORD_BCRYPT);

$existing = DB::table("panel_users")->where("email", $email)->first();

if ($existing) {
  DB::table("panel_users")->where("email", $email)->update([
    "password"   => $hash,
    "code"       => $code,
    "role"       => 1,
    "banned"     => 0,
    "updated_at" => $now,
  ]);
  echo "Admin updated\n";
} else {
  DB::table("panel_users")->insert([
    "name"       => "Admin",
    "email"      => $email,
    "password"   => $hash,
    "role"       => 1,
    "banned"     => 0,
    "code"       => $code,
    "created_at" => $now,
    "updated_at" => $now,
  ]);
  echo "Admin created\n";
}
' || fail "Failed to create/update admin"

# ========== Final output ==========
echo
box "Install finished successfully."
echo "Admin credentials:"
echo "  Email   : $ADMIN_EMAIL"
echo "  Password: $ADMIN_PASSWORD"
echo
ok "All done."

