#!/usr/bin/env bash
# Post-deploy smoke test. Read-only: /up, ping, sign-in, /me, one site's config, sign-out. Creates nothing.
#
# Usage:   SMOKE_PIN=<pin> infra/scripts/smoke.sh <base_url> <username>
#   e.g.   read -rs SMOKE_PIN && export SMOKE_PIN && infra/scripts/smoke.sh https://staging.example.org smmats
# The PIN comes from the environment only (never argv), so it does not show in `ps` or shell history.
# Exits non-zero on the first failed check.
set -euo pipefail

base="${1:-}"; user="${2:-}"
[[ -n "$base" && -n "$user" && -n "${SMOKE_PIN:-}" ]] || { echo "usage: SMOKE_PIN=<pin> $0 <base_url> <username>" >&2; exit 2; }
export SMOKE_PIN
base="${base%/}"
command -v curl >/dev/null || { echo "smoke: curl not found" >&2; exit 2; }
command -v jq >/dev/null || { echo "smoke: jq not found" >&2; exit 2; }

fail() { echo "FAIL  $*" >&2; exit 1; }
pass() { echo "ok    $*"; }

code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "$base/up")" || fail "/up unreachable"
[[ "$code" == "200" ]] || fail "/up returned $code"
pass "/up 200"

ping="$(curl -fsS --max-time 10 "$base/api/v1/ping")" || fail "/api/v1/ping"
[[ "$(jq -r .ok <<<"$ping")" == "true" ]] || fail "/api/v1/ping body: $ping"
pass "/api/v1/ping"

# Sign in with the PIN endpoint. Never log the token or the PIN.
# The body (with the PIN) is built from the environment and piped on stdin, so it never appears in any argv.
login="$(jq -nc --arg u "$user" '{username:$u,pin:env.SMOKE_PIN}' | curl -sS --max-time 15 -w '\n%{http_code}' -H 'Content-Type: application/json' -H 'Accept: application/json' --data-binary @- "$base/api/v1/auth/pin-login")" || fail "pin-login unreachable"
status="${login##*$'\n'}"; payload="${login%$'\n'*}"
[[ "$status" == "201" ]] || fail "pin-login returned $status"
token="$(jq -r .token <<<"$payload")"
[[ -n "$token" && "$token" != "null" ]] || fail "pin-login gave no token"
pass "pin-login as $user"
auth=(-H "Authorization: Bearer $token" -H 'Accept: application/json')

me="$(curl -fsS --max-time 10 "${auth[@]}" "$base/api/v1/me")" || fail "/me"
pass "/me -> $(jq -r '.user.name' <<<"$me")"

# There is no "list sites" endpoint; the sign-in response carries the user's sites. Read the first one's config (GET only).
site="${SMOKE_SITE:-$(jq -r '[.user.roles[].site_code | select(. != null)][0] // empty' <<<"$me")}"
if [[ -n "$site" ]]; then
  curl -fsS --max-time 15 "${auth[@]}" "$base/api/v1/sites/$site/config" -o /dev/null || fail "site config for $site"
  pass "site config readable ($site)"
else
  echo "skip  this user has only group roles; set SMOKE_SITE=<code> to check a site config"
fi

# Sign out so the smoke run leaves no live token behind.
curl -fsS --max-time 10 -X DELETE "${auth[@]}" "$base/api/v1/auth/session" -o /dev/null || fail "sign-out"
pass "signed out"

echo "smoke: all checks passed"
