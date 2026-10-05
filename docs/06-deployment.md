# OzePMS — Production Deployment (Ubuntu VPS)

Target: `https://ozepms.e2xinfotech.in` on one Ubuntu 24.04 LTS server with Nginx, PHP-FPM 8.3 with OPcache,
MySQL 8.4, Redis, a queue worker and the scheduler. Ready-made configuration files are in `deploy/`:

| File | Installed as |
|---|---|
| `deploy/nginx/ozepms.conf` | `/etc/nginx/sites-available/ozepms.conf` |
| `deploy/php/ozepms-fpm.conf` | `/etc/php/8.3/fpm/pool.d/ozepms.conf` |
| `deploy/php/99-ozepms.ini` | `/etc/php/8.3/fpm/conf.d/99-ozepms.ini` and `/etc/php/8.3/cli/conf.d/99-ozepms.ini` |
| `deploy/systemd/ozepms-queue.service` | `/etc/systemd/system/` |
| `deploy/systemd/ozepms-scheduler.{service,timer}` | `/etc/systemd/system/` |
| `public/errors/unavailable.html` | served by Nginx when PHP is down (502/503/504) |

Sizing for a first production server: 4 vCPU, 8 GB RAM, 80 GB SSD.

---

## 1. Server preparation

```bash
sudo apt update && sudo apt -y upgrade
sudo timedatectl set-timezone UTC          # the application stores UTC; properties have their own time zone
sudo adduser --system --group --home /var/www/ozepms --shell /bin/bash ozepms
sudo usermod -aG www-data ozepms

# Firewall: SSH + web only
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

Disable SSH password login (`PasswordAuthentication no` in `/etc/ssh/sshd_config`) once your key works,
and install `unattended-upgrades` and `fail2ban`.

## 2. Packages

```bash
sudo add-apt-repository -y ppa:ondrej/php      # PHP 8.3 builds with all extensions
sudo apt update
sudo apt -y install nginx libnginx-mod-http-headers-more-filter \
    php8.3-fpm php8.3-cli php8.3-mysql php8.3-bcmath php8.3-intl php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-gd php8.3-redis php8.3-opcache \
    mysql-server-8.4 redis-server certbot unzip git

# Composer
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer

# Node.js 22 (only needed where assets are built; you can also build in CI and upload public/build)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt -y install nodejs
```

If your Ubuntu release offers MySQL 8.0 only, add the official MySQL APT repository first and choose the 8.4 LTS series.

## 3. MySQL and Redis

```bash
sudo mysql_secure_installation
sudo mysql <<'SQL'
CREATE DATABASE ozepms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'ozepms'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON ozepms.* TO 'ozepms'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Recommended `/etc/mysql/mysql.conf.d/ozepms.cnf` for an 8 GB server:

```ini
[mysqld]
innodb_buffer_pool_size = 3G
innodb_log_file_size = 512M
innodb_flush_log_at_trx_commit = 1
max_connections = 200
slow_query_log = 1
long_query_time = 0.2
bind-address = 127.0.0.1
```

Redis: in `/etc/redis/redis.conf` keep `bind 127.0.0.1 ::1`, set `requirepass`, `maxmemory 512mb`,
`maxmemory-policy allkeys-lru`, then `sudo systemctl restart redis-server`.

## 4. PHP-FPM and OPcache

```bash
sudo cp deploy/php/ozepms-fpm.conf /etc/php/8.3/fpm/pool.d/ozepms.conf
sudo rm /etc/php/8.3/fpm/pool.d/www.conf                 # the default pool is not used
sudo cp deploy/php/99-ozepms.ini /etc/php/8.3/fpm/conf.d/99-ozepms.ini
sudo cp deploy/php/99-ozepms.ini /etc/php/8.3/cli/conf.d/99-ozepms.ini
sudo mkdir -p /var/log/php && sudo chown ozepms:www-data /var/log/php
sudo systemctl restart php8.3-fpm
```

`opcache.validate_timestamps = 0` means PHP never re-reads changed files on its own: **every deploy must reload PHP-FPM**
(step 7 does this). `expose_php = Off` removes the `X-Powered-By` header at the source; Nginx and the application remove it as well.

## 5. Application code

The layout keeps the last few releases so a deploy can be rolled back instantly:

```
/var/www/ozepms/
├── releases/20261005-1200/   ← one folder per deploy
├── shared/.env               ← secrets, never in git
├── shared/storage/           ← logs, uploads, sessions (survives deploys)
└── current → releases/…      ← symlink Nginx serves from
```

First-time setup (as the `ozepms` user):

```bash
sudo -iu ozepms
mkdir -p /var/www/ozepms/{releases,shared}
git clone <repository-url> /var/www/ozepms/releases/initial
cd /var/www/ozepms/releases/initial
cp .env.example /var/www/ozepms/shared/.env
mv storage /var/www/ozepms/shared/storage
```

Edit `/var/www/ozepms/shared/.env` — production values:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://ozepms.e2xinfotech.in
APP_KEY=                       # generated below

LOG_CHANNEL=oz
LOG_LEVEL=info

DB_DATABASE=ozepms
DB_USERNAME=ozepms
DB_PASSWORD=a-long-random-password

SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_PASSWORD=the-redis-password

TRUSTED_PROXIES=127.0.0.1
OZ_REQUIRE_2FA_PLATFORM=true
OZ_REQUIRE_2FA_OWNERS=true

MAIL_MAILER=smtp               # plus MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM_ADDRESS

OZ_ADMIN_EMAIL=admin@e2xinfotech.in
OZ_ADMIN_PASSWORD=             # empty: a strong password is generated and printed once
```

Then link the shared files, install and build:

```bash
cd /var/www/ozepms/releases/initial
ln -s /var/www/ozepms/shared/.env .env
ln -s /var/www/ozepms/shared/storage storage
composer install --no-dev --optimize-autoloader --classmap-authoritative
php artisan key:generate --force          # first time only
npm ci && npm run build && rm -rf node_modules
php artisan migrate --force --seed        # first time only: schema, reference data, roles, plans, Super Admin
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
ln -sfn /var/www/ozepms/releases/initial /var/www/ozepms/current
```

Never run `DemoSeeder` in production — it creates accounts with a known password.

Permissions: code is owned by `ozepms`; PHP-FPM runs as `ozepms` in group `www-data`;
only `storage/` and `bootstrap/cache/` need to be writable.

```bash
sudo chown -R ozepms:www-data /var/www/ozepms
sudo find /var/www/ozepms/shared/storage -type d -exec chmod 2775 {} \;
```

## 6. Nginx and HTTPS (Let's Encrypt)

```bash
sudo mkdir -p /var/www/letsencrypt
sudo cp deploy/nginx/ozepms.conf /etc/nginx/sites-available/ozepms.conf
sudo ln -s /etc/nginx/sites-available/ozepms.conf /etc/nginx/sites-enabled/ozepms.conf
sudo rm -f /etc/nginx/sites-enabled/default
```

The HTTPS server block needs a certificate before Nginx will start with it. Issue the first certificate with the
HTTP block only (comment out the `listen 443` server block for this one step), then restore it:

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo certbot certonly --webroot -w /var/www/letsencrypt -d ozepms.e2xinfotech.in \
     --email admin@e2xinfotech.in --agree-tos --no-eff-email
# restore the 443 block, then:
sudo nginx -t && sudo systemctl reload nginx
```

Renewal is automatic (`certbot.timer`). Reload Nginx after renewal:

```bash
echo 'systemctl reload nginx' | sudo tee /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
sudo chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh
sudo certbot renew --dry-run
```

## 7. Queue worker and scheduler

```bash
sudo cp deploy/systemd/ozepms-queue.service deploy/systemd/ozepms-scheduler.service deploy/systemd/ozepms-scheduler.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ozepms-queue.service
sudo systemctl enable --now ozepms-scheduler.timer
systemctl status ozepms-queue ozepms-scheduler.timer
```

* The queue worker sends e-mails (invitations, password links) and other background jobs. It restarts itself every
  hour (`--max-time`) and after each deploy (`queue:restart`).
* The scheduler runs every minute and handles subscription status changes (active → grace → expired), log and
  login-history clean-up and, from later phases, inventory horizon jobs.
* Alternative without systemd timers: `* * * * * cd /var/www/ozepms/current && php artisan schedule:run >> /dev/null 2>&1`
  in the `ozepms` user's crontab.

## 8. Deploying a new version

```bash
sudo -iu ozepms
REL=/var/www/ozepms/releases/$(date +%Y%m%d-%H%M)
git clone --depth 1 <repository-url> "$REL" && cd "$REL"
ln -s /var/www/ozepms/shared/.env .env
rm -rf storage && ln -s /var/www/ozepms/shared/storage storage
composer install --no-dev --optimize-autoloader --classmap-authoritative
npm ci && npm run build && rm -rf node_modules
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
ln -sfn "$REL" /var/www/ozepms/current
sudo systemctl reload php8.3-fpm           # clears OPcache (validate_timestamps = 0)
php artisan queue:restart                  # workers pick up the new code
ls -1dt /var/www/ozepms/releases/* | tail -n +6 | xargs rm -rf   # keep the last 5 releases
```

Rollback: point `current` at the previous release folder and reload PHP-FPM
(only if the release did not include a migration that must be reversed).

Use `php artisan down --secret=<token>` / `php artisan up` for maintenance windows; visitors see the branded 503 page.

## 9. Hiding framework fingerprints

The goal: nothing in URLs, headers, cookies or error pages reveals the language or framework.

| What | How |
|---|---|
| No `.php` in URLs | Nginx sends every request to the front controller internally (`location @app`). `/index.php/x` is 301-redirected to `/x`; any other `*.php` URL returns 404. |
| No `X-Powered-By` | `expose_php = Off` (PHP), `fastcgi_hide_header` / `more_clear_headers` (Nginx), removed again by `App\Http\Middleware\SecurityHeaders`. |
| No server version | `server_tokens off;` hides the version; `more_clear_headers Server;` (headers-more module) removes the header entirely. |
| Neutral cookie names | Session cookie is `oz_sid` (`SESSION_COOKIE`), CSRF cookie is set by the application; no `laravel_session`. |
| Custom error pages | 401/403/404/419/429/500/503 are rendered by `resources/views/errors/*.blade.php` in the OzePMS style with a reference number; `APP_DEBUG=false` so no stack traces. When PHP itself is down Nginx serves `public/errors/unavailable.html`. |
| No stray files | Hidden files (`.env`, `.git`) return 404; only `public/` is the web root; `robots.txt` disallows indexing of the application. |
| JSON errors | API errors use one neutral format `{ "error": { "code", "message", "ref" } }` without exception class names. |

Check after each deploy:

```bash
curl -sI https://ozepms.e2xinfotech.in/login | grep -iE 'server|x-powered-by|set-cookie'
curl -s -o /dev/null -w '%{http_code}\n' https://ozepms.e2xinfotech.in/index.php        # 301
curl -s -o /dev/null -w '%{http_code}\n' https://ozepms.e2xinfotech.in/anything.php     # 404
curl -s -o /dev/null -w '%{http_code}\n' https://ozepms.e2xinfotech.in/.env             # 404
```

## 10. Security headers and HTTPS

`SecurityHeaders` middleware sets a nonce-based Content-Security-Policy, `X-Frame-Options: DENY`,
`X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Cross-Origin-Opener-Policy`
and, on HTTPS, `Strict-Transport-Security` (1 year, preload). Signed-in pages are sent with `Cache-Control: no-store`.
Test with <https://securityheaders.com> and <https://www.ssllabs.com/ssltest/> (target: A+).

## 11. Logs, monitoring and backups

* Application logs: `storage/logs/ozepms-YYYY-MM-DD.log` (kept 30 days, `LOG_DAILY_DAYS`), separate `security`,
  `client` (browser errors) and `performance` (slow queries above `OZ_SLOW_QUERY_MS`) files.
  Grouped errors are visible to E2X staff at **Super Admin → System Health**.
* Nginx: `/var/log/nginx/ozepms.*.log`; PHP-FPM: `/var/log/php/ozepms-fpm.log`. Rotate with logrotate (default Ubuntu setup covers Nginx).
* Health check URL for uptime monitoring: `https://ozepms.e2xinfotech.in/up` (returns 200 when the application boots).
* Database backups, nightly, kept 14 days (add to root's crontab):

```bash
0 2 * * * mysqldump --single-transaction --quick --routines --triggers ozepms | gzip > /var/backups/ozepms-$(date +\%F).sql.gz && find /var/backups -name 'ozepms-*.sql.gz' -mtime +14 -delete
```

Copy backups off the server (object storage) and test a restore every month. Also back up `shared/.env` and `shared/storage/app`.
