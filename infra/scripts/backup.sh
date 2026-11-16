#!/usr/bin/env bash
# Tanaad database backup: mysqldump -> dated, compressed file, optional copy to MinIO/S3, retention prune.
#
# Usage:   infra/scripts/backup.sh
# Env:     DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD   connection (read from apps/api/.env.staging style env file if ENV_FILE set)
#          BACKUP_DIR        where dumps go            (default ./backups)
#          BACKUP_KEEP_DAYS  local retention in days   (default 14)
#          MINIO_ALIAS       an `mc` alias to sync to  (optional; e.g. "tanaad", configured with `mc alias set`)
#          MINIO_BUCKET      bucket for dumps          (default tanaad-backups)
# Offsite: ALWAYS keep a copy off the server. The MinIO sync below only helps if MinIO is on another host/disk.
# Restore: see infra/scripts/restore-drill.sh. Run nightly from cron, after the 03:30 ledger:verify.
set -euo pipefail

ENV_FILE="${ENV_FILE:-}"
if [[ -n "$ENV_FILE" ]]; then
  [[ -r "$ENV_FILE" ]] || { echo "backup: cannot read ENV_FILE $ENV_FILE" >&2; exit 2; }
  set -a
  # shellcheck disable=SC1090
  . "$ENV_FILE"
  set +a
fi

: "${DB_HOST:?DB_HOST is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
DB_PORT="${DB_PORT:-3306}"
BACKUP_DIR="${BACKUP_DIR:-./backups}"
BACKUP_KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
MINIO_BUCKET="${MINIO_BUCKET:-tanaad-backups}"

command -v mysqldump >/dev/null || { echo "backup: mysqldump not found" >&2; exit 2; }
mkdir -p "$BACKUP_DIR"
umask 077

stamp="$(date -u +%Y%m%dT%H%M%SZ)"
out="$BACKUP_DIR/${DB_DATABASE}-${stamp}.sql.gz"
tmp="$out.partial"
trap 'rm -f "$tmp"' EXIT

# Password goes through a temp defaults file, never on the command line (visible in `ps`).
cnf="$(mktemp)"
trap 'rm -f "$tmp" "$cnf"' EXIT
printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_PORT" "$DB_USERNAME" "$DB_PASSWORD" >"$cnf"

# --single-transaction: consistent InnoDB snapshot without locking writers.
# --routines --triggers: the append-only guard on `events` is a trigger and must survive a restore.
mysqldump --defaults-extra-file="$cnf" \
  --single-transaction --quick --routines --triggers --events \
  --set-gtid-purged=OFF --no-tablespaces \
  "$DB_DATABASE" | gzip -9 >"$tmp"

# A truncated gzip is the classic silent backup failure: test it before accepting the file.
gzip -t "$tmp"
mv "$tmp" "$out"
sha256sum "$out" >"$out.sha256"
echo "backup: wrote $out ($(du -h "$out" | cut -f1))"

if [[ -n "${MINIO_ALIAS:-}" ]]; then
  command -v mc >/dev/null || { echo "backup: MINIO_ALIAS set but mc not found" >&2; exit 2; }
  mc cp "$out" "$out.sha256" "${MINIO_ALIAS}/${MINIO_BUCKET}/"
  echo "backup: copied to ${MINIO_ALIAS}/${MINIO_BUCKET}/"
else
  echo "backup: MINIO_ALIAS not set; NOT copied offsite" >&2
fi

find "$BACKUP_DIR" -maxdepth 1 -type f \( -name "${DB_DATABASE}-*.sql.gz" -o -name "${DB_DATABASE}-*.sql.gz.sha256" \) -mtime "+${BACKUP_KEEP_DAYS}" -print -delete
