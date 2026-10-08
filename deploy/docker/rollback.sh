#!/usr/bin/env bash
# Switch visitors back to the previous colour (its web container is kept running after each deploy).
set -Eeuo pipefail
cd "${OZ_HOME:-$HOME/ozepms}"
set -a; . ./.env; set +a
LIVE="$(cat live)"; case "$LIVE" in blue) OTHER=green ;; green) OTHER=blue ;; *) echo "no live colour" >&2; exit 1 ;; esac
docker compose --profile "$OTHER" up -d "web_$OTHER" >/dev/null
for _ in $(seq 1 40); do [ "$(docker inspect --format '{{.State.Health.Status}}' "ozepms-web-$OTHER")" = "healthy" ] && break; sleep 3; done
[ "$(docker inspect --format '{{.State.Health.Status}}' "ozepms-web-$OTHER")" = "healthy" ] || { echo "$OTHER is not healthy" >&2; exit 1; }
docker compose stop "queue_$LIVE" "scheduler_$LIVE" >/dev/null 2>&1 || true
sed "s/@COLOR@/$OTHER/" router.conf.tpl > router/active.conf
docker exec ozepms-router nginx -t >/dev/null 2>&1 && docker exec ozepms-router nginx -s reload
echo "$OTHER" > live
docker compose --profile "$OTHER" up -d "queue_$OTHER" "scheduler_$OTHER" >/dev/null
echo "OK: $OTHER is live. (Database migrations are not undone.)"
