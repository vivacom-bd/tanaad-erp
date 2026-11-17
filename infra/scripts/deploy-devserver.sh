#!/usr/bin/env bash
# Deploy the dev-server stack (web + API + MySQL + Redis) from the checked-out repo. Run it on the dev host, from anywhere:
#   infra/scripts/deploy-devserver.sh [public-base-url]      (default http://<this host's public IP>:8083)
# It never touches other projects on the host. First run creates infra/devserver/*.env with generated secrets and seeds
# the demo dataset into an EMPTY database; later runs only migrate. It never runs migrate:fresh.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
DC=(docker compose -f infra/docker-compose.devserver.yml)
BASE_URL="${1:-http://$(curl -fsS https://api.ipify.org):8083}"
ENVDIR=infra/devserver

echo "==> secrets"
if [ ! -f "$ENVDIR/db.env" ]; then
  umask 077
  sed -e "s/MYSQL_ROOT_PASSWORD=CHANGE_ME/MYSQL_ROOT_PASSWORD=$(openssl rand -hex 24)/" \
      -e "s/MYSQL_PASSWORD=CHANGE_ME/MYSQL_PASSWORD=$(openssl rand -hex 24)/" "$ENVDIR/db.env.example" > "$ENVDIR/db.env"
fi
if [ ! -f "$ENVDIR/api.env" ]; then
  umask 077
  DBPW="$(grep '^MYSQL_PASSWORD=' "$ENVDIR/db.env" | cut -d= -f2)"
  sed -e "s#APP_URL=.*#APP_URL=$BASE_URL#" -e "s/DB_PASSWORD=CHANGE_ME/DB_PASSWORD=$DBPW/" \
      -e "s/TANAAD_LEDGER_KEY=CHANGE_ME/TANAAD_LEDGER_KEY=$(openssl rand -hex 32)/" "$ENVDIR/api.env.example" > "$ENVDIR/api.env"
  # APP_KEY is generated once by the API image below.
  KEY="$(docker run --rm php:8.5-cli-alpine php -r 'echo "base64:".base64_encode(random_bytes(32));')"
  sed -i "s#^APP_KEY=.*#APP_KEY=$KEY#" "$ENVDIR/api.env"
fi

echo "==> api dependencies"
docker build -q -f infra/php/Dockerfile -t tanaad-php:dev . >/dev/null
docker run --rm -v "$ROOT/apps/api:/var/www/api" -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer tanaad-php:dev \
  composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
mkdir -p apps/api/storage/{app,framework/{cache,sessions,views},logs} apps/api/bootstrap/cache
chown -R 82:82 apps/api/storage apps/api/bootstrap/cache

echo "==> web build (real mode, same origin: $BASE_URL/api/v1)"
corepack enable >/dev/null 2>&1 || true
pnpm install --frozen-lockfile
VITE_DEMO_ACCOUNTS=1 VITE_API_URL="$BASE_URL/api/v1" pnpm --filter @tanaad/web build

echo "==> start"
"${DC[@]}" up -d --build
"${DC[@]}" exec -T api php artisan migrate --force

if [ "$("${DC[@]}" exec -T api php artisan tinker --execute='echo Schema::hasTable("users") ? \DB::table("users")->count() : -1;' 2>/dev/null | tr -dc '0-9-')" = "0" ]; then
  echo "==> empty database: seeding the demo dataset"
  "${DC[@]}" exec -T api php artisan db:seed --force
fi
"${DC[@]}" exec -T api php artisan config:cache
"${DC[@]}" exec -T api php artisan route:cache
"${DC[@]}" restart queue scheduler web >/dev/null  # web too: nginx resolves the api address at start

echo "==> check"
curl -fsS -o /dev/null -w "web %{http_code}\n" "http://127.0.0.1:8083/"
curl -fsS -o /dev/null -w "api /up %{http_code}\n" "http://127.0.0.1:8083/up"
"${DC[@]}" ps
