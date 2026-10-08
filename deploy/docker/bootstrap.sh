#!/usr/bin/env bash
# One-time setup of the OzePMS Docker project on the server. Safe to re-run (never overwrites secrets).
#   bash bootstrap.sh <pms-domain> <booking-domain> <letsencrypt-email>
# Creates ~/ozepms (secrets, router, state), starts only the database and the router. Touches nothing else.
set -Eeuo pipefail
DOMAIN="${1:?usage: bootstrap.sh pms.domain booking.domain email}"
BOOKING_DOMAIN="${2:?usage: bootstrap.sh pms.domain booking.domain email}"
LE_EMAIL="${3:?usage: bootstrap.sh pms.domain booking.domain email}"
HOME_DIR="${OZ_HOME:-$HOME/ozepms}"
SRC="$(cd "$(dirname "$0")" && pwd)"

docker network inspect proxy >/dev/null 2>&1 || { echo "The shared Docker network 'proxy' was not found. Stop here and check how nginx-proxy is set up." >&2; exit 1; }
mkdir -p "$HOME_DIR"/{router,backups,state}
cp "$SRC"/compose.yml "$SRC"/deploy.sh "$SRC"/rollback.sh "$SRC"/backup.sh "$HOME_DIR"/ 2>/dev/null || true
cp "$SRC"/router.conf.tpl "$HOME_DIR"/
chmod +x "$HOME_DIR"/*.sh
cd "$HOME_DIR"

rand() { openssl rand -base64 33 | tr -dc 'A-Za-z0-9' | head -c "$1"; }

if [ ! -f db.env ]; then
    ROOT_PW="$(rand 28)"; APP_PW="$(rand 28)"
    umask 077
    printf 'MYSQL_ROOT_PASSWORD=%s\nMYSQL_DATABASE=ozepms\nMYSQL_USER=ozepms\nMYSQL_PASSWORD=%s\n' "$ROOT_PW" "$APP_PW" > db.env
    sed -e "s|__APP_KEY__|base64:$(openssl rand -base64 32)|" -e "s|__DOMAIN__|$DOMAIN|" -e "s|__BOOKING_DOMAIN__|$BOOKING_DOMAIN|" -e "s|__DB_PASSWORD__|$APP_PW|" "$SRC/app.env.example" > app.env
    echo "created db.env and app.env (random secrets)"
else
    echo "db.env exists, kept"
fi
chmod 600 db.env app.env

umask 022
cat > .env <<ENVF
DOMAIN=$DOMAIN
BOOKING_DOMAIN=$BOOKING_DOMAIN
LE_EMAIL=$LE_EMAIL
BLUE_IMAGE=
GREEN_IMAGE=
ENVF
[ -f live ] || : > live
sed 's/@COLOR@/blue/' router.conf.tpl > router/active.conf

docker compose up -d db router
echo
echo "Done. Next: edit ~/ozepms/app.env (set MAIL_* and, if wanted, the Super Admin/Admin e-mails), then run the pipeline (or deploy.sh)."
