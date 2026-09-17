#!/usr/bin/env bash
set -Eeuo pipefail
export APP_ROOT="${APP_ROOT:-/home/deploy/apps/classic-stack}"
export RELEASE_ID="${1:?release id is required}"
[[ "$RELEASE_ID" =~ ^[a-z0-9][a-z0-9-]{0,79}$ ]] || { echo 'Invalid release id' >&2; exit 1; }
release_path="$APP_ROOT/releases/$RELEASE_ID"
env_file="$APP_ROOT/shared/env/production.env"
test -f "$env_file"
test -d "$release_path"
# Serialize manual deployments and Actions alike.
exec 9>"$APP_ROOT/deploy.lock"
flock -n 9 || { echo 'Another deployment is running' >&2; exit 1; }
infra() { docker compose --env-file "$env_file" -f "$release_path/deploy/compose.infra.yaml" "$@"; }
release() { docker compose --env-file "$env_file" -p "release-$RELEASE_ID" -f "$release_path/deploy/compose.release.yaml" "$@"; }
previous="$(readlink "$APP_ROOT/current" || true)"
if [[ "$previous" == "$release_path" ]]; then echo 'Already active'; exit 0; fi
previous_id="${previous##*/}"
old_release() { RELEASE_ID="$previous_id" docker compose --env-file "$env_file" -p "release-$previous_id" -f "$previous/deploy/compose.release.yaml" "$@"; }
mkdir -p "$APP_ROOT/shared/proxy"
chmod 600 "$env_file"
# Values are read as dotenv by Compose, never executed as shell code.
infra config --quiet
# APP_DOMAIN is only used for the Caddy site address; reject config injection.
domain="$(sed -n 's/^APP_DOMAIN=//p' "$env_file" | tr -d '\r')"
[[ "$domain" =~ ^([a-zA-Z0-9][a-zA-Z0-9.-]*|:80)$ ]] || { echo 'APP_DOMAIN must be a hostname (or :80 for an HTTP-only test)' >&2; exit 1; }
if grep -Eq 'GENERATE_ONCE|REPLACE_WITH|YOUR_EMAIL' "$env_file"; then echo 'Complete production.env before deploying' >&2; exit 1; fi
if [[ ! -f "$APP_ROOT/shared/proxy/Caddyfile" ]]; then
  printf ':80 {\n respond "Preparing the first release" 503\n}\n' > "$APP_ROOT/shared/proxy/Caddyfile"
fi
infra up -d --wait postgres redis
infra up -d proxy
if [[ -f "$release_path/image.tar.gz" ]]; then
  gzip -dc "$release_path/image.tar.gz" | docker load
  rm "$release_path/image.tar.gz"
fi
docker image inspect "classic-stack:$RELEASE_ID" >/dev/null
docker volume create classic-stack-storage >/dev/null
docker run --rm --user root --entrypoint sh -v classic-stack-storage:/data "classic-stack:$RELEASE_ID" -c 'mkdir -p /data/private /data/public && chown -R www-data:www-data /data'
cp "$APP_ROOT/shared/proxy/Caddyfile" "$APP_ROOT/shared/proxy/Caddyfile.previous"
old_stopped=0
rollback() {
  result=$?
  trap - ERR
  set +e
  echo 'Deployment failed; restoring the previous proxy configuration.' >&2
  cp "$APP_ROOT/shared/proxy/Caddyfile.previous" "$APP_ROOT/shared/proxy/Caddyfile"
  infra exec -T proxy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
  if [[ "$old_stopped" == 1 && -n "$previous" ]]; then old_release up -d horizon scheduler; fi
  release down --timeout 60
  exit "$result"
}
trap rollback ERR
# Expand/contract migrations only: the old app remains live against this database.
release run --rm --no-deps --user www-data app php artisan migrate --force
release up -d --wait --wait-timeout 150 app
# Stop old consumers gracefully before starting new ones. Queued jobs remain in Redis.
if [[ -n "$previous" ]]; then
  old_stopped=1
  old_release stop -t 60 horizon scheduler
fi
release up -d horizon scheduler
sleep 3
release exec -T horizon php artisan horizon:status
for service in horizon scheduler; do
  test "$(docker inspect -f '{{.State.Running}}' "$(release ps -q "$service")")" = true
done
printf '%s {\n encode gzip\n reverse_proxy app-%s:80\n}\n' "$domain" "$RELEASE_ID" > "$APP_ROOT/shared/proxy/Caddyfile.next"
infra exec -T proxy caddy validate --config /etc/caddy/Caddyfile.next --adapter caddyfile
mv "$APP_ROOT/shared/proxy/Caddyfile.next" "$APP_ROOT/shared/proxy/Caddyfile"
infra exec -T proxy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
# The candidate was checked against Postgres and Redis before switching traffic.
ln -sfn "$release_path" "$APP_ROOT/current.next"
mv -Tf "$APP_ROOT/current.next" "$APP_ROOT/current"
trap - ERR
if [[ -n "$previous" ]]; then
  ln -sfn "$previous" "$APP_ROOT/previous"
  # Allow requests already accepted by the previous web container to complete.
  sleep "${DRAIN_SECONDS:-30}"
  old_release stop -t 60 app
fi
echo "Activated $RELEASE_ID. Previous release retained for rollback."
# Images/releases are deliberately retained; see the runbook for selective cleanup.
