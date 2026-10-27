#!/usr/bin/env bash
set +x
set -euo pipefail
umask 077
# All writes are confined to a NEW private staging folder/container. No host DB client.
fail() { printf '%s\n' 'STOP: isolated staging prerequisites/rehearsal failed. Do not share private logs or DDL.' >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || fail
command -v docker >/dev/null && command -v composer >/dev/null && command -v php >/dev/null || fail
docker info >/dev/null 2>&1 || fail  # Never install/start/change Docker or production services.
php -r 'exit(PHP_VERSION_ID>=80400 && extension_loaded("pdo_mysql") ? 0:1);' || fail
capture=${1:?Private capture path required}; sha=${2:?Pinned capture hash required}
[[ "$capture" =~ ^/home/mgye-api-sulb/sulb-private-schema-[a-f0-9]{24}/schema-private\.json$ ]] || fail
[[ "$sha" =~ ^[a-f0-9]{64}$ ]] || fail
[ -f "$capture" ] && [ ! -L "$capture" ] || fail
actual=$(sha256sum -- "$capture"); [ "${actual%% *}" = "$sha" ] || fail
# Bounded load; refuse instead of affecting a resource-constrained live VPS.
available=$(awk '/^MemAvailable:/{print $2}' /proc/meminfo)
[ "${available:-0}" -ge 1572864 ] || fail
[ "$(df -Pk /home/mgye-api-sulb | awk 'NR==2{print $4}')" -ge 2097152 ] || fail
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
[ -z "$(git -c safe.directory="$root" -C "$root" status --porcelain)" ] || fail
work=$(mktemp -d /home/mgye-api-sulb/sulb-schema-stage.XXXXXXXX)
chmod 700 "$work"
container="sulb-schema-stage-$(openssl rand -hex 16)"
created=0
cleanup() { if [ "$created" = 1 ]; then docker rm -f "$container" >/dev/null 2>&1 || true; fi; }
trap cleanup EXIT
trap 'fail' ERR
mkdir -m 700 "$work/source" "$work/composer-home" "$work/composer-cache"
# Archive tracked candidate source only; never copy a live application tree/.env/storage.
git -c safe.directory="$root" -C "$root" archive HEAD backend ops/sulb-rc docs/erp-baseline/proposed-migrations | tar -x -C "$work/source"
if find "$work/source" -type f -name '.env*' ! -name '.env.example' -print -quit | grep -q .; then fail; fi
if find "$work/source/backend/bootstrap/cache" -type f ! -name '.gitignore' -print -quit | grep -q .; then fail; fi
mkdir "$work/socket"
# Only this socket directory is mounted; its ancestor is root-only 0700.
chmod 777 "$work/socket"
image='percona/percona-server@sha256:eaa4cf955f8a01a43faa6ef656bf8fb69a17c17c278a3b0514212291ca0448b1'
docker pull "$image" >"$work/image.log" 2>&1
docker create --name "$container" --network none --memory 512m --cpus 1 --pids-limit 256 \
  --tmpfs /var/lib/mysql:rw,nosuid,size=768m,mode=1777 \
  --mount "type=bind,src=$work/socket,dst=/qa-socket" \
  -e MYSQL_ALLOW_EMPTY_PASSWORD=yes "$image" \
  --datadir="/var/lib/mysql/sulb-shipment-${container##*-}" \
  --skip-networking --socket=/qa-socket/mysql.sock --mysqlx=0 \
  --innodb-buffer-pool-size=128M --restrict-fk-on-non-standard-key=ON >"$work/container-id" 2>"$work/container-error.log"
created=1
[ "$(docker inspect --format '{{.HostConfig.NetworkMode}}' "$container")" = none ] || fail
[ "$(docker inspect --format '{{len .HostConfig.PortBindings}}' "$container")" = 0 ] || fail
docker start "$container" >"$work/start.log" 2>&1
ready=0
for attempt in $(seq 1 90); do
  if docker exec "$container" mysqladmin --no-defaults --socket=/qa-socket/mysql.sock --user=root --silent ping >"$work/readiness.log" 2>&1; then ready=1; break; fi
  sleep 2
done
[ "$ready" = 1 ] || fail
# Fresh private dependencies; no plugins, scripts, live vendor or application config.
env -i PATH="$PATH" HOME="$work" COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME="$work/composer-home" COMPOSER_CACHE_DIR="$work/composer-cache" \
  composer --working-dir="$work/source/backend" install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins >"$work/dependencies.log" 2>&1
datadir="/var/lib/mysql/sulb-shipment-${container##*-}"
env -i PATH="$PATH" HOME="$work" php -d display_errors=0 -d log_errors=0 \
  "$work/source/ops/sulb-rc/run-private-socket-rehearsal.php" \
  "$work/socket/mysql.sock" "$datadir" "$capture" "$sha" "$work/verification.json" >"$work/private-test.log" 2>&1
test -s "$work/verification.json" || fail
docker rm -f "$container" >"$work/cleanup.log" 2>&1
created=0
printf 'SAFE_SUMMARY_FILE=%s/verification.json\n' "$work"
cat "$work/verification.json"
