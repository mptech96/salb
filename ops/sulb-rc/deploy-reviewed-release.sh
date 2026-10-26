#!/usr/bin/env bash
set -euo pipefail
umask 077
tools=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
if [ "${1:---plan}" = --plan ]; then
  printf '%s\n' 'NO-GO candidate. Planned order only:' \
    'GitHub -> /home/sulb-release pinned clean commit -> reviewed write fence' \
    'Private code/data backup -> rsync Backend/Frontend -> Composer' \
    'Explicit approved migrations -> Frontend build -> restart -> smoke tests' \
    'No automatic database restore or migration rollback.'
  exit 78
fi
[ "${1:-}" = --execute ] || exit 2
# This candidate ALWAYS stops here. Only a separately reviewed approval commit
# with all evidence, exact production baseline and migration executor may proceed.
php "$tools/check-approval.php"
test "$(pwd -P)" = /home/sulb-release
test "$(git rev-parse HEAD)" = "${SULB_APPROVED_COMMIT:?Pinned approved commit required}"
test -z "$(git status --porcelain)"
: "${SULB_BACKUP_CNF:?Protected dedicated backup-client config required}"
: "${SULB_WRITE_FENCE_RECEIPT:?Reviewed web/queue/import write-fence evidence required}"
test -s "$SULB_WRITE_FENCE_RECEIPT"
test "$(stat -c %a "$SULB_BACKUP_CNF")" = 600
test "$(stat -c %u "$SULB_BACKUP_CNF")" = "$(id -u)"
backend=/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech
frontend=/home/mgye-sulb/htdocs/sulb.mgye.tech
backup=$(mktemp -d /home/mgye-api-sulb/sulb-release-backup.XXXXXXXX)
php "$tools/preflight.php" > "$backup/preflight.json"
php -r '$m=json_decode(file_get_contents($argv[1]),true);if(($m["schema_matches_approved_baseline"]??false)!==true)exit(78);' "$backup/preflight.json"
if command -v mariadb-dump >/dev/null; then dump=mariadb-dump; else dump=mysqldump; fi
"$dump" --defaults-extra-file="$SULB_BACKUP_CNF" --user=sulb_backup_ro --single-transaction --routines --triggers --events --hex-blob --databases sulbprod > "$backup/database.sql"
test -s "$backup/database.sql"
tar -czf "$backup/backend.tar.gz" -C "$backend" .
tar -czf "$backup/frontend.tar.gz" -C "$frontend" .
(cd "$backup" && sha256sum database.sql backend.tar.gz frontend.tar.gz > SHA256SUMS)
(cd "$backup" && sha256sum --check SHA256SUMS)
printf 'BACKUP_DIR=%s\n' "$backup"
trap 'echo "STOP: retain write fence; do not restore database over live transactions. Reviewed forward repair or compatible code-only rollback required." >&2' ERR
php "$backend/artisan" down --retry=60
rsync -a --exclude='.git/' --exclude='.env*' --exclude='storage/' --exclude='bootstrap/cache/' --exclude='vendor/' --exclude='tests/' --exclude='public/storage' --exclude='public/uploads/' backend/ "$backend/"
rsync -a --exclude='.git/' --exclude='.env*' --exclude='.next*/' --exclude='node_modules/' --exclude='public/uploads/' frontend/ "$frontend/"
(cd "$backend" && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader)
while IFS= read -r migration; do
  (cd "$backend" && php artisan migrate --force --path="$migration")
done < <(php "$tools/check-approval.php" --migrations)
(cd "$frontend" && npm ci && npm run build)
systemctl restart sulb-frontend.service
php "$backend/artisan" up
curl --fail --silent --show-error https://api.sulb.mgye.tech/up >/dev/null
curl --fail --silent --show-error https://sulb.mgye.tech/ >/dev/null
printf 'HTTP smoke passed; business UAT and authorized write-fence release still required.\n'
