#!/usr/bin/env bash
# Restore drill: load the latest dump into a SCRATCH database, run ledger:verify --replay against it,
# and compare row counts with the live database. Proves the backup is restorable and the ledger intact.
#
# Usage:   infra/scripts/restore-drill.sh <scratch_db_name> [dump_file]
#   scratch_db_name  database to (re)create and fill. Refuses `tanaad` and the live DB_DATABASE.
#   dump_file        default: newest $BACKUP_DIR/${DB_DATABASE}-*.sql.gz
# Env:     DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD   the live connection (read-only use)
#          DRILL_ADMIN_USER / DRILL_ADMIN_PASSWORD  an account allowed to CREATE/DROP the scratch DB (default: DB_USERNAME)
#          BACKUP_DIR (default ./backups)   API_DIR (default apps/api next to this repo)
#          ALLOW_DROP=1  required to drop the scratch database if it already exists
# The live database is only read (row counts). Nothing is ever dropped except the named scratch DB.
set -euo pipefail

scratch="${1:-}"
dump="${2:-}"
[[ -n "$scratch" ]] || { echo "usage: $0 <scratch_db_name> [dump_file]" >&2; exit 2; }

if [[ ! "$scratch" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "drill: scratch name must be letters, digits, underscore" >&2; exit 2
fi
: "${DB_HOST:?DB_HOST is required}"
: "${DB_DATABASE:?DB_DATABASE is required}"
: "${DB_USERNAME:?DB_USERNAME is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
DB_PORT="${DB_PORT:-3306}"

# Guard rails: never the production name, never the live database, and the name must say it is scratch.
case "$scratch" in
  tanaad | tanaad_prod | tanaad_production | "$DB_DATABASE")
    echo "drill: refusing to restore into '$scratch' (live or protected database)" >&2; exit 3 ;;
esac
if [[ "$scratch" != *scratch* && "$scratch" != *drill* && "$scratch" != tanaad_restore_* ]]; then
  echo "drill: scratch database name must contain 'scratch' or 'drill', or start with tanaad_restore_" >&2; exit 3
fi

BACKUP_DIR="${BACKUP_DIR:-./backups}"
API_DIR="${API_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../apps/api" && pwd)}"
if [[ -z "$dump" ]]; then
  # shellcheck disable=SC2012
  dump="$(ls -1t "$BACKUP_DIR/${DB_DATABASE}"-*.sql.gz 2>/dev/null | head -n1 || true)"
fi
[[ -n "$dump" && -r "$dump" ]] || { echo "drill: no readable dump found (BACKUP_DIR=$BACKUP_DIR)" >&2; exit 2; }
if [[ -r "$dump.sha256" ]]; then
  (cd "$(dirname "$dump")" && sha256sum -c "$(basename "$dump").sha256") || { echo "drill: checksum mismatch, dump is damaged" >&2; exit 4; }
fi
gzip -t "$dump"

mk_cnf() { # $1 user $2 password
  local f; f="$(mktemp)"
  printf '[client]\nhost=%s\nport=%s\nuser=%s\npassword=%s\n' "$DB_HOST" "$DB_PORT" "$1" "$2" >"$f"
  echo "$f"
}
live_cnf="$(mk_cnf "$DB_USERNAME" "$DB_PASSWORD")"
admin_cnf="$(mk_cnf "${DRILL_ADMIN_USER:-$DB_USERNAME}" "${DRILL_ADMIN_PASSWORD:-$DB_PASSWORD}")"
trap 'rm -f "$live_cnf" "$admin_cnf"' EXIT

exists="$(mysql --defaults-extra-file="$admin_cnf" -N -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='${scratch}'")"
if [[ "$exists" != "0" ]]; then
  [[ "${ALLOW_DROP:-}" == "1" ]] || { echo "drill: '$scratch' exists; set ALLOW_DROP=1 to recreate it" >&2; exit 3; }
  mysql --defaults-extra-file="$admin_cnf" -e "DROP DATABASE \`${scratch}\`"
fi
mysql --defaults-extra-file="$admin_cnf" -e "CREATE DATABASE \`${scratch}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"

echo "drill: restoring $dump into $scratch"
gunzip -c "$dump" | mysql --defaults-extra-file="$admin_cnf" "$scratch"

echo "drill: ledger:verify --replay on $scratch"
(cd "$API_DIR" && DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" DB_DATABASE="$scratch" DB_USERNAME="${DRILL_ADMIN_USER:-$DB_USERNAME}" DB_PASSWORD="${DRILL_ADMIN_PASSWORD:-$DB_PASSWORD}" \
  php artisan ledger:verify --replay)

echo "drill: comparing row counts (live vs restored)"
tables="$(mysql --defaults-extra-file="$live_cnf" -N -e "SELECT table_name FROM information_schema.tables WHERE table_schema='${DB_DATABASE}' AND table_type='BASE TABLE' ORDER BY 1")"
bad=0
while IFS= read -r t; do
  [[ -n "$t" ]] || continue
  live="$(mysql --defaults-extra-file="$live_cnf" -N -e "SELECT COUNT(*) FROM \`${DB_DATABASE}\`.\`${t}\`")"
  rest="$(mysql --defaults-extra-file="$admin_cnf" -N -e "SELECT COUNT(*) FROM \`${scratch}\`.\`${t}\`" 2>/dev/null || echo MISSING)"
  # The live DB keeps growing after the dump, so live >= restored is expected; a restored count above live, or a missing table, is not.
  if [[ "$rest" == "MISSING" ]] || (( rest > live )); then
    printf 'MISMATCH %-40s live=%s restored=%s\n' "$t" "$live" "$rest"; bad=1
  else
    printf 'ok       %-40s live=%s restored=%s\n' "$t" "$live" "$rest"
  fi
done <<<"$tables"

if (( bad )); then echo "drill: FAILED" >&2; exit 1; fi
echo "drill: PASSED (scratch database $scratch left in place; drop it when done)"
