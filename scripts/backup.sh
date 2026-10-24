#!/bin/bash
# Daily backup of the panel database and .env (plan section 8, "Backups").
#
# cPanel cron (once a day, e.g. 01:30):
#   30 1 * * * /home/USER/mailpanel/scripts/backup.sh >> /home/USER/backups/backup.log 2>&1
#
# This writes to /home/USER/backups. A backup that lives on the same server is not enough:
# copy that folder off the server too (rclone, rsync over SSH, or download it weekly).

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"
KEEP_DAYS="${KEEP_DAYS:-30}"
STAMP="$(date +%Y%m%d-%H%M%S)"

env_value() { grep -E "^$1=" "$APP_DIR/.env" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

DB_HOST="$(env_value DB_HOST)"
DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_PASS="$(env_value DB_PASSWORD)"

umask 077
mkdir -p "$BACKUP_DIR"

# Password goes through a temporary option file so it never shows up in `ps`.
CNF="$(mktemp)"
trap 'rm -f "$CNF"' EXIT
printf '[client]\nuser=%s\npassword=%s\nhost=%s\n' "$DB_USER" "$DB_PASS" "${DB_HOST:-localhost}" > "$CNF"

mysqldump --defaults-extra-file="$CNF" --single-transaction --no-tablespaces "$DB_NAME" | gzip > "$BACKUP_DIR/db-$STAMP.sql.gz"
cp "$APP_DIR/.env" "$BACKUP_DIR/env-$STAMP"

# Drop old backups.
find "$BACKUP_DIR" -type f \( -name 'db-*.sql.gz' -o -name 'env-*' \) -mtime +"$KEEP_DAYS" -delete

echo "$(date -Is) backup ok: db-$STAMP.sql.gz"
