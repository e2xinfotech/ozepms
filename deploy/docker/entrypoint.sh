#!/bin/sh
# Roles: web (default) | queue | scheduler | artisan <args>
set -e
cd /var/www/html

prepare() {
    mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
    [ -e public/storage ] || su-exec www-data php artisan storage:link --force >/dev/null 2>&1 || true
}

caches() {
    su-exec www-data php artisan config:cache --no-interaction
    su-exec www-data php artisan route:cache --no-interaction
    su-exec www-data php artisan view:cache --no-interaction
    su-exec www-data php artisan event:cache --no-interaction
}

role="${1:-web}"
[ $# -gt 0 ] && shift

case "$role" in
    web)
        prepare; caches
        exec /usr/bin/supervisord -c /etc/supervisord.conf
        ;;
    queue)
        prepare; caches
        exec su-exec www-data php artisan queue:work --sleep=3 --tries=3 --backoff=10 --max-time=3600 --memory=256
        ;;
    scheduler)
        prepare; caches
        exec su-exec www-data php artisan schedule:work --no-interaction
        ;;
    artisan)
        prepare
        exec su-exec www-data php artisan "$@"
        ;;
    *)
        exec "$role" "$@"
        ;;
esac
