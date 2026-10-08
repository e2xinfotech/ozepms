#!/usr/bin/env bash
# Nightly database dump. Add to the crontab of the deploy user:
#   0 2 * * * bash /home/ubuntu/ozepms/backup.sh >> /home/ubuntu/ozepms/backups/backup.log 2>&1
set -Eeuo pipefail
cd "${OZ_HOME:-$HOME/ozepms}"
docker exec ozepms-db sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --quick --routines --triggers "$MYSQL_DATABASE"' | gzip > "backups/ozepms-$(date +%F).sql.gz"
find backups -name 'ozepms-*.sql.gz' -mtime +14 -delete
