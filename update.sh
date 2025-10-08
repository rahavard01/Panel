#!/usr/bin/env bash
set -Eeuo pipefail

# ================== Config ==================
REPO_URL_DEFAULT="https://github.com/rahavard01/Panel.git"
PRESERVE_PATHS=("public/uploads" "storage/app/public")

# ================== Helpers ==================
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

php_bin() {
  if [ -n "${PHP_BIN-}" ] && [ -x "${PHP_BIN}" ]; then
    echo "$PHP_BIN"
  elif [ -x "/www/server/php/82/bin/php" ]; then
    echo "/www/server/php/82/bin/php"
  else
    echo "php"
  fi
}
run_php() { "$(php_bin)" "$@"; }

is_tracked() {
  git ls-files --error-unmatch "$1" >/dev/null 2>&1
}

ensure_storage_symlink() {
  local link="public/storage"
  local target="storage/app/public"

  # Correct symlink present
  if [ -L "$link" ]; then
    local dest
    dest="$(readlink "$link" || true)"
    if [ "$dest" = "../$target" ] || [ "$dest" = "$target" ]; then
      ok "storage symlink already correct ($link -> $dest)"
      return 0
    else
      warn "storage symlink points to '$dest'; fixing…"
      rm -f "$link"
    fi
  elif [ -e "$link" ]; then
    # Exists but not a symlink
    warn "$link exists but is not a symlink; moving aside"
    mv "$link" "$link.bak.$(date +%s)" || rm -rf "$link"
  fi

  # Create symlink quietly
  run_php artisan storage:link >/dev/null 2>&1 && ok "storage symlink created" || warn "storage:link failed"
}

# ================== Preflight ==================
box "Panel Updater"

PROJECT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$PROJECT_DIR"

need git
need composer
need sed
need rsync

git rev-parse --is-inside-work-tree >/dev/null 2>&1 || fail "This directory is not a Git repository."

TOP="$(git rev-parse --show-toplevel)"
cd "$TOP"

[ -f ".env" ] || fail ".env not found. Run install first."

step "Authorizing this repo path for Git (safe.directory)"
git config --system --add safe.directory "$TOP" 2>/dev/null || true
git config --global --add safe.directory "$TOP" 2>/dev/null || true
ok "safe.directory set for: $TOP"

step "Ensuring 'origin' remote exists"
if ! git remote | grep -qx 'origin'; then
  REPO_URL="${REPO_URL:-$REPO_URL_DEFAULT}"
  git remote add origin "$REPO_URL"
  ok "Added origin → $REPO_URL"
else
  ok "origin already set"
fi

# ================== Maintenance Down ==================
APP_WAS_PUT_DOWN="false"
cleanup() {
  if [ "$APP_WAS_PUT_DOWN" = "true" ]; then
    step "Bringing application up"
    run_php artisan up || true
  fi
}
trap cleanup EXIT

step "Putting application into maintenance mode"
if run_php artisan down; then
  APP_WAS_PUT_DOWN="true"
else
  warn "artisan down failed (continuing)"
fi

# ================== Git Sync ==================
step "Resolving upstream branch"
UPSTREAM="$(git rev-parse --abbrev-ref --symbolic-full-name @{u} 2>/dev/null || true)"
if [ -z "$UPSTREAM" ]; then
  CURR_BRANCH="$(git rev-parse --abbrev-ref HEAD || echo '')"
  if [ -n "$CURR_BRANCH" ] && git ls-remote --exit-code --heads origin "$CURR_BRANCH" >/dev/null 2>&1; then
    UPSTREAM="origin/$CURR_BRANCH"
  elif git ls-remote --exit-code --heads origin main >/dev/null 2>&1; then
    UPSTREAM="origin/main"
  elif git ls-remote --exit-code --heads origin master >/dev/null 2>&1; then
    UPSTREAM="origin/master"
  else
    fail "Could not resolve an upstream branch. Make sure 'origin' is set."
  fi
fi
ok "Using upstream: $UPSTREAM"

step "Fetching latest code"
git fetch --all --prune

TMP_BACKUP="$(mktemp -d)"
RESTORE_LIST=()
for p in "${PRESERVE_PATHS[@]}"; do
  if [ -e "$p" ] && ! is_tracked "$p"; then
    step "Preserving untracked path: $p"
    mkdir -p "$(dirname "$TMP_BACKUP/$p")"
    if [ -d "$p" ]; then
      rsync -a "$p/" "$TMP_BACKUP/$p/" || true
    else
      rsync -a "$p" "$TMP_BACKUP/$p" || true
    fi
    RESTORE_LIST+=("$p")
  fi
done

step "Resetting to upstream (HARD)"
git reset --hard "$UPSTREAM"

step "Cleaning untracked files/dirs"
git clean -fd

if [ "${#RESTORE_LIST[@]}" -gt 0 ]; then
  step "Restoring preserved paths"
  for p in "${RESTORE_LIST[@]}"; do
    mkdir -p "$(dirname "$p")"
    if [ -d "$TMP_BACKUP/$p" ]; then
      rsync -a "$TMP_BACKUP/$p/" "$p/" || true
    else
      rsync -a "$TMP_BACKUP/$p" "$p" || true
    fi
  done
fi
rm -rf "$TMP_BACKUP"

# ================== Composer ==================
step "Installing PHP dependencies (no-dev, optimized)"
export COMPOSER_MEMORY_LIMIT=-1
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# ================== Merge .env.example -> .env (append-only) ==================
if [ -f ".env.example" ]; then
  step "Merging new keys from .env.example into .env"
  TMPPHP="$(mktemp)"
  cat >"$TMPPHP" <<'PHP'
<?php
$env = __DIR__ . "/.env";
$ex  = __DIR__ . "/.env.example";
if (!file_exists($env) || !file_exists($ex)) { exit(0); }

$linesEnv = file($env, FILE_IGNORE_NEW_LINES);
$linesEx  = file($ex,  FILE_IGNORE_NEW_LINES);

$envKeys = [];
foreach ($linesEnv as $line) {
  if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=/', $line, $m)) {
    $envKeys[$m[1]] = true;
  }
}

$append = [];
foreach ($linesEx as $line) {
  if (preg_match('/^\s*([A-Za-z0-9_]+)\s*=/', $line, $m)) {
    $k = $m[1];
    if (!isset($envKeys[$k])) {
      $append[] = $line;
    }
  }
}

if ($append) {
  file_put_contents($env, "\n# === added from .env.example by update.sh ===\n" . implode("\n", $append) . "\n", FILE_APPEND);
  $keys = array_map(function($l){ return preg_replace('/=.*/', '', $l); }, $append);
  echo "Added keys: " . implode(', ', $keys) . PHP_EOL;
} else {
  echo "No new keys to add." . PHP_EOL;
}
PHP
  run_php "$TMPPHP" || warn "Env merge script error (skipped)"
  rm -f "$TMPPHP"
else
  warn ".env.example not found; skipping env merge"
fi

# ================== Cache & Migrate & Optimize ==================
step "Clearing caches"
run_php artisan config:clear || true
run_php artisan route:clear || true
run_php artisan view:clear  || true

step "Running migrations (forced)"
run_php artisan migrate --force

step "Ensuring storage symlink (public/storage → storage/app/public)"
ensure_storage_symlink

step "Optimizing (config/routes cache, etc.)"
run_php artisan optimize || true

step "Priming caches"
run_php artisan config:cache || true
run_php artisan route:cache  || true
run_php artisan view:cache   || true

# ================== Restart workers (optional) ==================
if run_php artisan list 2>/dev/null | grep -q "horizon:terminate"; then
  step "Restarting Horizon"
  run_php artisan horizon:terminate || true
elif run_php artisan list 2>/dev/null | grep -q "queue:restart"; then
  step "Restarting queue workers"
  run_php artisan queue:restart || true
fi

# ================== Maintenance Up ==================
step "Bringing application up"
run_php artisan up || true
APP_WAS_PUT_DOWN="false"

# ================== Report ==================
echo
box "Update finished successfully."
echo "Current commit:"
git log -1 --pretty=format:'  %h %s (%ci)' || true
echo
ok "All done."
