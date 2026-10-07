# OzePMS — Local Setup (macOS)

## Quick start (one command)

```bash
cd ~/Downloads/ozepms            # the project folder
bash deploy/local/setup-mac.sh   # first time and after every update (keeps your data)
bash deploy/local/start-mac.sh   # starts the site and opens http://127.0.0.1:8000/login
```

The setup script uses the PHP, Composer, Node.js and MySQL you already have (it installs only what is
missing, through Homebrew), keeps working database settings already in `.env`, otherwise creates the
database `ozepms` with the user `ozepms`, then installs dependencies, creates tables and demo hotels on
the first run, applies new database changes on later runs, builds the screens and prints the sign-in
details. Local only: two-step verification is off and e-mails are written to `storage/logs`.
If your MySQL root user has a password: `MYSQL_ROOT_PASSWORD='…' bash deploy/local/setup-mac.sh`.

The sections below describe the same steps by hand.

This guide sets up OzePMS on a Mac for development at `http://ozepms.test`.
It takes about 20 minutes on a fresh machine.

| Tool | Version | Installed with |
|---|---|---|
| PHP | 8.3 (with `bcmath`, `intl`, `mbstring`, `pdo_mysql`, `sodium`, `gd`) | Homebrew or Laravel Herd |
| Composer | 2.x | Homebrew |
| MySQL | 8.4 LTS | Homebrew |
| Node.js | 22 LTS (with npm) | Homebrew |
| Local web server | Laravel Herd **or** Laravel Valet | see step 4 |

SQLite is **not** supported: the schema uses MySQL CHECK constraints, generated columns and FULLTEXT indexes.

---

## 1. Homebrew packages

```bash
# Homebrew itself: https://brew.sh
brew update
brew install php@8.3 composer mysql@8.4 node@22
brew link --force --overwrite php@8.3
brew link --force --overwrite node@22

php -v          # PHP 8.3.x
php -m | grep -E 'bcmath|intl|mbstring|pdo_mysql|sodium'
node -v         # v22.x
```

Recommended `php.ini` values for development (`php --ini` shows the file):

```ini
memory_limit = 512M
upload_max_filesize = 10M
post_max_size = 12M
expose_php = Off
```

## 2. MySQL 8.4

```bash
brew services start mysql@8.4
mysql -uroot <<'SQL'
CREATE DATABASE ozepms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE ozepms_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ozepms'@'localhost' IDENTIFIED BY 'choose-a-local-password';
GRANT ALL PRIVILEGES ON ozepms.* TO 'ozepms'@'localhost';
GRANT ALL PRIVILEGES ON ozepms_test.* TO 'ozepms'@'localhost';
FLUSH PRIVILEGES;
SQL
```

The automated tests connect as `root` with an empty password over `127.0.0.1` (see `phpunit.xml`).
If your root user has a password, override it when running tests: `DB_PASSWORD=... php artisan test`.

## 3. Get the code and install dependencies

```bash
cd ~/Sites            # or any folder you like
git clone <repository-url> ozepms
cd ozepms

composer install
npm ci

cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_URL=http://ozepms.test
DB_DATABASE=ozepms
DB_USERNAME=ozepms
DB_PASSWORD=choose-a-local-password

# First Super Admin (leave the password empty to have one generated and printed once)
OZ_ADMIN_EMAIL=you@e2xinfotech.in
OZ_ADMIN_PASSWORD=
```

Create the tables and reference data:

```bash
php artisan migrate --seed                  # schema, countries, currencies, permissions, roles, plans, Super Admin
php artisan db:seed --class=DemoSeeder      # optional: demo properties P1001/P1002 and demo users
php artisan storage:link
```

`DemoSeeder` prints the demo sign-in details once (default password from `OZ_DEMO_PASSWORD`, `Demo@12345`):
`owner@demo.ozepms.test`, `manager@demo.ozepms.test`, `frontdesk@demo.ozepms.test`.
The owner and the Super Admin must set up two-step verification at first sign-in
(turn this off locally with `OZ_REQUIRE_2FA_OWNERS=false` / `OZ_REQUIRE_2FA_PLATFORM=false` if needed).

**Sign-in (one login page for everybody): `http://ozepms.test/login`**

| Who | E-mail | Password | Lands on |
|---|---|---|---|
| Super Admin | `OZ_ADMIN_EMAIL` (default `admin@e2xinfotech.in`) | `OZ_ADMIN_PASSWORD` from `.env`; if empty, a password is generated and printed once by `migrate --seed` | `/admin` |
| Property owner (demo) | `owner@demo.ozepms.test` | `OZ_DEMO_PASSWORD` (default `Demo@12345`) | `/properties` → P1001 / P1002 |
| Hotel manager (demo) | `manager@demo.ozepms.test` | same | `/p/P1001/dashboard` |
| Front desk (demo) | `frontdesk@demo.ozepms.test` | same | `/p/P1001/dashboard` (fewer menus) |

Lost or unknown password: `php artisan user:reset-password admin@e2xinfotech.in` (prints a new one once) or
`php artisan user:reset-password admin@e2xinfotech.in --password='Your#Strong2026'`.
Public booking engine (no login): `http://ozepms.test/book/P1001`.

## 4. Serve the site at `http://ozepms.test`

### Option A — Laravel Herd (simplest)

1. Install Herd from <https://herd.laravel.com> and choose PHP 8.3 in *Settings → PHP*.
2. *Settings → General → Herd Paths*: add the parent folder of the project (e.g. `~/Sites`).
   The project is then available at `http://ozepms.test` (the folder name becomes the host name).
3. Herd ships its own nginx and PHP-FPM; MySQL from Homebrew keeps working.
   If you use Herd's bundled PHP, run `composer`/`php artisan` from Herd's terminal integration so the same PHP is used.

### Option B — Laravel Valet

```bash
composer global require laravel/valet
export PATH="$HOME/.composer/vendor/bin:$PATH"   # add to ~/.zshrc
valet install
cd ~/Sites/ozepms
valet link ozepms          # → http://ozepms.test
valet isolate php@8.3      # pin this site to PHP 8.3
```

Either way, open <http://ozepms.test> and sign in.

## 5. Front-end assets

```bash
npm run dev      # Vite dev server with hot reload (keep it running while you work)
npm run build    # type-check (TypeScript strict) and production build into public/build
```

Every page in `resources/js/pages/**` is its own entry point; `npm run build` must pass before handing work over.

## 6. Background work

The scheduler runs subscription status changes, log clean-up, the nightly inventory horizon (`inventory:horizon`) and the monthly retention of old daily rows (`inventory:archive`); the queue sends e-mails.

```bash
php artisan schedule:work          # runs the scheduler every minute in the foreground
php artisan queue:work --tries=3   # processes queued jobs (e-mails, notifications)
php artisan reports:refresh --all  # rebuild report figures once after importing data (bookings keep them current)
```

During development e-mails are written to the log (`MAIL_MAILER=log`); see `storage/logs/`.

## 7. Tests

```bash
php artisan test                         # full suite against ozepms_test (MySQL)
php artisan test --filter=UsersEndpointsTest
DB_DATABASE=my_other_test_db php artisan test   # use another test database
```

`tests/Arch/NoRawDatabaseAccessTest.php` fails the build if application code opens its own database connections.

## 8. Troubleshooting

| Symptom | Fix |
|---|---|
| `No application encryption key has been specified` | `php artisan key:generate` |
| `SQLSTATE[HY000] [2002]` | MySQL is not running: `brew services start mysql@8.4` |
| Blank page, console shows Vite manifest error | run `npm run build` (or keep `npm run dev` running) |
| `ozepms.test` does not resolve | Herd: check the site appears in *Sites*; Valet: `valet links`, then `valet restart` |
| Sign-in always says "too many attempts" | the sign-in rate limit is per IP; wait a minute or `php artisan cache:clear` |
| Redirected to *Security & Sign-in* after login | two-step verification is required for this account; scan the QR code with an authenticator app |
