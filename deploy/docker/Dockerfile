# OzePMS application image: nginx + PHP-FPM in one container (web), or the same image running the queue / scheduler.
# The release archive already contains vendor/ (production packages) and public/build, so no Composer or Node here.
FROM php:8.3-fpm-alpine

COPY --from=mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN apk add --no-cache nginx supervisor curl tzdata su-exec \
 && install-php-extensions bcmath intl gd pdo_mysql zip opcache pcntl exif \
 && rm -rf /var/cache/apk/* /tmp/*

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/99-ozepms.ini
COPY deploy/docker/php-fpm-pool.conf /usr/local/etc/php-fpm.d/zz-ozepms.conf
COPY deploy/docker/nginx-app.conf /etc/nginx/nginx.conf
COPY deploy/docker/supervisord.conf /etc/supervisord.conf
COPY deploy/docker/entrypoint.sh /usr/local/bin/oz-entrypoint
RUN chmod +x /usr/local/bin/oz-entrypoint

WORKDIR /var/www/html
COPY . /var/www/html
RUN rm -rf storage/logs/* storage/framework/cache/* storage/framework/sessions/* storage/framework/views/* bootstrap/cache/*.php \
 && mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080
HEALTHCHECK --interval=10s --timeout=5s --start-period=40s --retries=6 CMD curl -fsS http://127.0.0.1:8080/up || exit 1
ENTRYPOINT ["oz-entrypoint"]
CMD ["web"]
