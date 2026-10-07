#!/usr/bin/env bash
# OzePMS — one-step local setup for macOS (safe to run again after every update).
#
#   bash deploy/local/setup-mac.sh
#
# Installs what is missing (Homebrew packages PHP 8.3+, Composer, MySQL 8.4, Node 22), creates the
# local database, installs dependencies, prepares .env, creates the tables and demo data on the first
# run, applies new database changes on later runs, builds the screens and prints the sign-in details.
# Optional: OZ_DB_NAME, OZ_DB_USER, OZ_DB_PASSWORD, OZ_ADMIN_PASSWORD, MYSQL_ROOT_PASSWORD (if your MySQL root has one).
# Database settings already in .env are kept when they work.
set -euo pipefail

cd "$(dirname "$0")/../.."
ROOT="$(pwd)"
DB_NAME="${OZ_DB_NAME:-ozepms}"
DB_USER="${OZ_DB_USER:-ozepms}"
DB_PASS="${OZ_DB_PASSWORD:-Local#2026}"
ADMIN_PASS="${OZ_ADMIN_PASSWORD:-Admin#E2x2026}"
URL="http://127.0.0.1:8000"

say()  { printf '\n\033[1;34m▸ %s\033[0m\n' "$1"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '\n\033[1;31m✗ %s\033[0m\n' "$1"; exit 1; }

# ---------------------------------------------------------------- tools
say "Checking tools"
BREW=""
command -v brew >/dev/null 2>&1 && BREW="$(brew --prefix)"
need_brew() { [ -n "$BREW" ] || fail "$1 is missing. Install it (or Homebrew from https://brew.sh) and run this script again."; }

php_ok() { command -v php >/dev/null 2>&1 && php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; }
if ! php_ok; then
    need_brew "PHP 8.3+"
    say "Installing PHP 8.3"
    brew install php@8.3
    brew link --force --overwrite php@8.3
    export PATH="$BREW/opt/php@8.3/bin:$PATH"
fi
php_ok || fail "PHP 8.3 or newer is not on the PATH. Open a new Terminal window and run the script again."
for ext in bcmath intl mbstring pdo_mysql sodium; do
    php -m | grep -qi "^$ext$" || fail "PHP extension '$ext' is missing (Homebrew PHP includes it; check 'which php')."
done
ok "PHP $(php -r 'echo PHP_VERSION;')"

if ! command -v composer >/dev/null 2>&1; then need_brew "Composer"; say "Installing Composer"; brew install composer; fi
ok "Composer $(composer --version 2>/dev/null | awk '{print $3}')"

node_ok() { command -v node >/dev/null 2>&1 && [ "$(node -p 'process.versions.node.split(".")[0]')" -ge 20 ]; }
if ! node_ok; then
    need_brew "Node.js 20+"
    say "Installing Node.js 22"
    brew install node@22
    brew link --force --overwrite node@22 || true
    export PATH="$BREW/opt/node@22/bin:$PATH"
fi
node_ok || fail "Node.js 20 or newer is not on the PATH. Open a new Terminal window and run the script again."
ok "Node $(node -v)"

# ---------------------------------------------------------------- MySQL
say "Checking the database"
[ -f .env ] || cp .env.example .env
# Existing settings in .env are kept when they connect (your own MySQL user / database).
envval() { php -r '$e = @parse_ini_file(".env", false, INI_SCANNER_RAW) ?: []; echo trim($e[$argv[1]] ?? "", "\"'"'"'");' "$1"; }
try_db() { php -r '
    try { $p = new PDO("mysql:host=".$argv[1].";port=".$argv[2], $argv[4], $argv[5], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
          $p->exec("CREATE DATABASE IF NOT EXISTS `".$argv[3]."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); exit(0);
    } catch (Throwable $e) { fwrite(STDERR, "  ".$e->getMessage()."\n"); exit(1); }' "$@"; }
DB_HOST="$(envval DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(envval DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
CUR_NAME="$(envval DB_DATABASE)"; CUR_USER="$(envval DB_USERNAME)"; CUR_PASS="$(envval DB_PASSWORD)"
if [ -n "$CUR_NAME" ] && [ -n "$CUR_USER" ] && try_db "$DB_HOST" "$DB_PORT" "$CUR_NAME" "$CUR_USER" "$CUR_PASS" 2>/dev/null; then
    DB_NAME="$CUR_NAME"; DB_USER="$CUR_USER"; DB_PASS="$CUR_PASS"
    ok "Using your database settings from .env ($DB_USER@$DB_HOST:$DB_PORT / $DB_NAME)"
else
    MYSQL_BIN="$(command -v mysql || true)"
    if [ -z "$MYSQL_BIN" ] && [ -n "$BREW" ]; then
        for f in mysql@8.4 mysql; do [ -x "$BREW/opt/$f/bin/mysql" ] && { MYSQL_BIN="$BREW/opt/$f/bin/mysql"; break; }; done
    fi
    if [ -z "$MYSQL_BIN" ]; then
        need_brew "MySQL 8"
        say "Installing MySQL 8.4"
        brew install mysql@8.4
        MYSQL_BIN="$BREW/opt/mysql@8.4/bin/mysql"
    fi
    if [ -n "$BREW" ]; then brew services start mysql@8.4 >/dev/null 2>&1 || brew services start mysql >/dev/null 2>&1 || true; fi
    root_sql() {
        if [ -n "${MYSQL_ROOT_PASSWORD:-}" ]; then "$MYSQL_BIN" -uroot -p"$MYSQL_ROOT_PASSWORD" -h"$DB_HOST" -P"$DB_PORT" -e "$1"
        else "$MYSQL_BIN" -uroot -e "$1" 2>/dev/null || "$MYSQL_BIN" -uroot -h"$DB_HOST" -P"$DB_PORT" -e "$1"; fi
    }
    for i in $(seq 1 20); do root_sql "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
    root_sql "SELECT 1" >/dev/null 2>&1 || fail "Cannot connect to MySQL as root. Either put your own MySQL user in .env (DB_DATABASE, DB_USERNAME, DB_PASSWORD) or run:  MYSQL_ROOT_PASSWORD='…' bash deploy/local/setup-mac.sh"
    root_sql "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;"
    try_db "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_USER" "$DB_PASS" || fail "The new database user cannot connect."
    ok "Database '$DB_NAME' ready (user '$DB_USER')"
fi
export URL DB_HOST DB_PORT DB_NAME DB_USER DB_PASS ADMIN_PASS

# ---------------------------------------------------------------- dependencies
say "Installing dependencies (first run takes a few minutes)"
composer install --no-interaction --prefer-dist --no-progress
npm ci --no-audit --no-fund
ok "Dependencies installed"

# ---------------------------------------------------------------- .env
say "Preparing settings (.env)"
php -r '
$file = ".env"; $env = file_get_contents($file);
$set = ["APP_ENV" => "local", "APP_DEBUG" => "true", "APP_URL" => getenv("URL"), "DB_HOST" => getenv("DB_HOST"), "DB_PORT" => getenv("DB_PORT"),
    "DB_DATABASE" => getenv("DB_NAME"), "DB_USERNAME" => getenv("DB_USER"), "DB_PASSWORD" => "\"".str_replace("\"", "\\\"", getenv("DB_PASS"))."\"",
    "OZ_REQUIRE_2FA_PLATFORM" => "false", "OZ_REQUIRE_2FA_OWNERS" => "false", "MAIL_MAILER" => "log", "QUEUE_CONNECTION" => "sync",
    "OZ_ADMIN_PASSWORD" => "\"".getenv("ADMIN_PASS")."\""];
foreach ($set as $k => $v) {
    $line = $k."=".$v;
    $env = preg_match("/^".$k."=.*$/m", $env) ? preg_replace("/^".$k."=.*$/m", str_replace("$", "\\$", $line), $env) : rtrim($env)."\n".$line."\n";
}
file_put_contents($file, $env);
' 
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force --no-interaction >/dev/null
php artisan config:clear >/dev/null
ok "Settings ready (local only: two-step verification off, e-mails written to storage/logs)"

# ---------------------------------------------------------------- database
# First run = no hotels in the database yet (also when tables were created by hand before).
HAS_DATA="$(php -r 'try { $p = new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT").";dbname=".getenv("DB_NAME"), getenv("DB_USER"), getenv("DB_PASS"));
    echo (int) $p->query("SELECT COUNT(*) FROM properties")->fetchColumn() > 0 ? 1 : 0; } catch (Throwable $e) { echo 0; }')"
if [ "$HAS_DATA" != "1" ]; then
    say "Creating tables, reference data and demo hotels (first run)"
    php artisan migrate:fresh --seed --force --no-interaction
    php artisan db:seed --class=DemoSeeder --force --no-interaction
else
    say "Applying new database changes"
    php artisan migrate --force --no-interaction
fi
# Local copy: the Super Admin always gets the password printed below.
php artisan user:reset-password admin@e2xinfotech.in --password="$ADMIN_PASS" >/dev/null
php artisan reports:refresh --all >/dev/null
php artisan storage:link >/dev/null 2>&1 || true
ok "Database ready"

# ---------------------------------------------------------------- screens
say "Building the screens"
npm run build >/dev/null
php artisan optimize:clear >/dev/null
ok "Screens built"

cat <<INFO

$(printf '\033[1;32m')OzePMS is ready.$(printf '\033[0m')  Start it any time with:   bash deploy/local/start-mac.sh

  Sign in:          $URL/login
  Super Admin:      admin@e2xinfotech.in        / $ADMIN_PASS
  Owner (demo):     owner@demo.ozepms.test      / Demo@12345
  Manager (demo):   manager@demo.ozepms.test    / Demo@12345
  Front desk:       frontdesk@demo.ozepms.test  / Demo@12345
  Booking engine:   $URL/book/P1001   (public, no sign-in)

After each new update from E2X, run this setup script again (it keeps your data).
INFO
