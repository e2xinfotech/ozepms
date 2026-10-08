#!/usr/bin/env bash
# Blue/green release on the Docker host.   bash deploy.sh /path/release.tar.gz <git-sha>
set -Eeuo pipefail
ARTIFACT="${1:?usage: deploy.sh release.tar.gz git-sha}"
SHA="${2:?usage: deploy.sh release.tar.gz git-sha}"
cd "${OZ_HOME:-$HOME/ozepms}"
log() { printf '\033[1;34m[deploy]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[deploy] %s\033[0m\n' "$*" >&2; exit 1; }

exec 9>.deploy.lock; flock -n 9 || die "Another deployment is running."
[ -f app.env ] && [ -f db.env ] || die "Run bootstrap.sh first."
set -a; . ./.env; set +a
SHORT="${SHA:0:12}"
LIVE="$(cat live 2>/dev/null || true)"
case "$LIVE" in blue) NEW=green ;; green) NEW=blue ;; *) LIVE=""; NEW=blue ;; esac
OLD="$LIVE"
NEWU="$(echo "$NEW" | tr a-z A-Z)"
IMAGE="ozepms-app:$SHORT"
log "live: ${LIVE:-none} -> deploying $SHORT to $NEW"

set_image() { # $1 = BLUE|GREEN  $2 = image
    grep -v "^$1_IMAGE=" .env > .env.tmp || true
    echo "$1_IMAGE=$2" >> .env.tmp && mv .env.tmp .env; export "$1_IMAGE=$2"   # compose prefers the shell value, so keep it in step with the file
}
compose() { docker compose "$@"; }
point_router() { sed "s/@COLOR@/$1/" router.conf.tpl > router/active.conf; docker exec ozepms-router nginx -t >/dev/null 2>&1 && docker exec ozepms-router nginx -s reload; }
wait_healthy() { # $1 container
    for _ in $(seq 1 60); do
        [ "$(docker inspect --format '{{.State.Health.Status}}' "$1" 2>/dev/null)" = "healthy" ] && return 0
        sleep 3
    done
    return 1
}
rollback() {
    if [ -n "$OLD" ] && [ "$(cat live 2>/dev/null)" != "$OLD" ]; then
        log "ROLLBACK to $OLD"; point_router "$OLD"; echo "$OLD" > live
        compose --profile "$OLD" up -d "queue_$OLD" "scheduler_$OLD" >/dev/null 2>&1 || true
        compose stop "queue_$NEW" "scheduler_$NEW" >/dev/null 2>&1 || true
    fi
}
trap 'rc=$?; [ $rc -ne 0 ] && { printf "\033[1;31m[deploy] failed (exit %s)\033[0m\n" "$rc" >&2; rollback; }; exit $rc' EXIT

# 1. Build the image from the release
BUILD="builds/$SHORT"; rm -rf "$BUILD"; mkdir -p "$BUILD"
tar --warning=no-unknown-keyword -xzf "$ARTIFACT" -C "$BUILD"
docker build -q -t "$IMAGE" -f "$BUILD/deploy/docker/Dockerfile" "$BUILD" >/dev/null
rm -rf "$BUILD"

# 2. Database up, backup, migrate
compose up -d db router >/dev/null
for _ in $(seq 1 40); do [ "$(docker inspect --format '{{.State.Health.Status}}' ozepms-db)" = "healthy" ] && break; sleep 3; done
log "database backup"
docker exec ozepms-db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --quick --routines --triggers "$MYSQL_DATABASE"' | gzip > "backups/pre-$SHORT-$(date +%Y%m%d%H%M%S).sql.gz"
ls -1t backups/pre-*.sql.gz 2>/dev/null | tail -n +15 | xargs -r rm -f
artisan() { docker run --rm --network ozepms_default --env-file app.env -v ozepms_storage:/var/www/html/storage "$IMAGE" artisan "$@"; }
log "migrating"
artisan migrate --force --no-interaction
if [ ! -f state/seeded ]; then
    log "first install: reference data, roles, plans and the first accounts (passwords are printed once, also saved in state/first-seed.log)"
    umask 077; artisan db:seed --force --no-interaction | tee state/first-seed.log; touch state/seeded; umask 022
else
    artisan db:seed --class=PermissionSeeder --force --no-interaction >/dev/null
fi

# 3. Start the idle colour and check it
set_image "$NEWU" "$IMAGE"
compose --profile "$NEW" up -d "web_$NEW" >/dev/null
log "waiting for $NEW to become healthy"
wait_healthy "ozepms-web-$NEW" || die "$NEW did not become healthy; live version unchanged."
[ "$(docker exec "ozepms-web-$NEW" curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/login)" = "200" ] || die "$NEW login page check failed."

# 4. Switch: only the new colour runs queue and scheduler
[ -n "$OLD" ] && compose stop "queue_$OLD" "scheduler_$OLD" >/dev/null 2>&1 || true
point_router "$NEW"; echo "$NEW" > live
compose --profile "$NEW" up -d "queue_$NEW" "scheduler_$NEW" >/dev/null

# 5. Smoke test through the shared proxy on this host (a server often cannot reach its own public IP, so the name is resolved locally)
sleep 3
for _ in $(seq 1 10); do
    curl -fsSk --max-time 8 --resolve "$DOMAIN:443:127.0.0.1" "https://$DOMAIN/up" | grep -q '"status":"ok"' && OKPUB=1 && break; sleep 3
done
[ "${OKPUB:-0}" = 1 ] || die "public smoke test failed (is DNS / the certificate ready for $DOMAIN?)."

trap - EXIT
# 6. Keep the previous colour's web container for instant rollback; drop older images
docker images ozepms-app --format '{{.Tag}} {{.CreatedAt}}' | sort -k2 -r | awk 'NR>3 {print "ozepms-app:"$1}' | xargs -r docker rmi >/dev/null 2>&1 || true
log "done: $NEW is live ($SHORT). Rollback: bash ~/ozepms/rollback.sh"
