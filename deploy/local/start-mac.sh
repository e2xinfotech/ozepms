#!/usr/bin/env bash
# OzePMS — start the local site on macOS:   bash deploy/local/start-mac.sh   (Ctrl+C stops it)
set -euo pipefail
cd "$(dirname "$0")/../.."
[ -f .env ] || { echo "Run 'bash deploy/local/setup-mac.sh' first."; exit 1; }
if command -v brew >/dev/null 2>&1; then
    brew services start mysql@8.4 >/dev/null 2>&1 || brew services start mysql >/dev/null 2>&1 || true
fi
URL="http://127.0.0.1:8000"
( sleep 2 && open "$URL/login" >/dev/null 2>&1 || true ) &
echo "OzePMS is running at $URL  —  keep this window open, press Ctrl+C to stop."
exec php artisan serve --host=127.0.0.1 --port=8000
