#!/usr/bin/env bash
set -euo pipefail
umask 077
tools=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
blocked() { printf 'BLOCKED: %s\n' "$1" >&2; exit 78; }

check_previous_release() {
  local backup receipt
  [[ ! -e /run/sulb-release.fence && ! -L /run/sulb-release.fence ]] || blocked WRITE_FENCE_ALREADY_PRESENT
  [[ ! -e /home/mgye-api-sulb/htdocs/api.sulb.mgye.tech/storage/framework/down && ! -L /home/mgye-api-sulb/htdocs/api.sulb.mgye.tech/storage/framework/down ]] || blocked BACKEND_MAINTENANCE_ALREADY_PRESENT
  for backup in /home/mgye-api-sulb/sulb-release-backup.*; do
    [[ -d "$backup" ]] || continue
    if [[ -e "$backup/fence-summary.json" || -e "$backup/ordered-upgrade-events.jsonl" || -e "$backup/application-copy-started" ]]; then
      [[ -f "$backup/release-summary.json" && ! -L "$backup/release-summary.json" ]] || blocked PREVIOUS_RELEASE_REQUIRES_REVIEW
      IFS= read -r receipt < "$backup/release-summary.json" || blocked PREVIOUS_RELEASE_REQUIRES_REVIEW
      [[ "$receipt" == '{"write_fence":"RELEASED",'* ]] || blocked PREVIOUS_RELEASE_REQUIRES_REVIEW
    fi
  done
}

inspect_frontend_toolchain() {
  local pid service_node entry service_path=
  [[ "$(systemctl show sulb-frontend.service --property=User --value)" == mgye-sulb ]] || blocked FRONTEND_SERVICE_USER_MISMATCH
  pid=$(systemctl show sulb-frontend.service --property=MainPID --value)
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || blocked FRONTEND_SERVICE_NOT_RUNNING
  [[ "$(stat -c %u "/proc/$pid")" == "$(id -u mgye-sulb)" ]] || blocked FRONTEND_PROCESS_UID_MISMATCH
  service_node=$(realpath -e "/proc/$pid/exe") || blocked FRONTEND_SERVICE_EXECUTABLE_UNREADABLE
  [[ "${service_node##*/}" == node ]] || blocked FRONTEND_SERVICE_NODE_UNPROVEN
  [[ -r "/proc/$pid/environ" ]] || blocked FRONTEND_SERVICE_PATH_UNREADABLE
  while IFS= read -r -d '' entry; do
    case "$entry" in PATH=*) service_path=${entry#PATH=}; break ;; esac
  done < "/proc/$pid/environ"
  # Only absolute, unambiguous PATH entries from the running service. Never use them for root commands.
  [[ "$service_path" =~ ^/[a-zA-Z0-9_./+-]+(:/[a-zA-Z0-9_./+-]+)*$ ]] || blocked FRONTEND_SERVICE_PATH_INVALID
  frontend_path=$service_path
  frontend_node=$(frontend_run /bin/sh -c 'command -v node') || blocked FRONTEND_NODE_MISSING
  frontend_npm=$(frontend_run /bin/sh -c 'command -v npm') || blocked FRONTEND_NPM_MISSING
  [[ "$frontend_node" == /* && "$frontend_npm" == /* ]] || blocked FRONTEND_TOOLS_NOT_ABSOLUTE
  [[ "$(realpath -e "$frontend_node")" == "$service_node" ]] || blocked FRONTEND_NODE_SERVICE_MISMATCH
  frontend_run "$frontend_node" -e 'const [a,b]=process.versions.node.split(".").map(Number);if(a<20||(a===20&&b<9))process.exit(78)' || blocked FRONTEND_NODE_VERSION_UNSUPPORTED
  frontend_run "$frontend_npm" --version >/dev/null || blocked FRONTEND_NPM_UNUSABLE
  [[ "$(systemctl show sulb-frontend.service --property=MainPID --value)" == "$pid" ]] || blocked FRONTEND_SERVICE_PID_CHANGED
}

frontend_run() {
  runuser -u mgye-sulb -- env -i PATH="$frontend_path" HOME=/home/mgye-sulb NODE_ENV=production SULB_QA_UOM=0 NEXT_PUBLIC_API_URL=https://api.sulb.mgye.tech/api NODE_OPTIONS=--max-old-space-size=2048 "$@"
}

if [ "${1:-}" = --inspect-frontend ]; then
  [[ "$(id -u)" == 0 && "$(uname -s)" == Linux ]] || blocked LINUX_ROOT_REQUIRED
  check_previous_release
  mode=$(stat -c %a /home/sulb-release)
  printf 'RELEASE_MODE=%s\nPREVIOUS_RELEASE_MARKERS=CLEAR\n' "$mode"
  inspect_frontend_toolchain
  printf 'FRONTEND_NODE=%s\nFRONTEND_NPM=%s\n' "$frontend_node" "$frontend_npm"
  [[ "$(stat -c %u /home/sulb-release)" == 0 && "$mode" =~ ^[0-7]{3,4}$ ]] || blocked RELEASE_CHECKOUT_OWNER_OR_MODE_INVALID
  (( (8#$mode & 0022) == 0 )) || blocked RELEASE_CHECKOUT_GROUP_OR_WORLD_WRITABLE
  exit 0
fi
if [ "${1:-}" = --inventory ]; then
  python3 - <<'SULB_INVENTORY_PY'
import os,sys,re,json,pathlib,time,stat
BASE=pathlib.Path('/home/mgye-api-sulb')
PREFIX=re.compile(r'^sulb-(?:native-schema|history-restore|history-ready|release-backup)[-.][A-Za-z0-9_-]+$')

def footprint(path,budget=100000):
    path=pathlib.Path(path)
    if path.is_symlink() or not path.is_dir():raise ValueError('REAL_DIRECTORY_REQUIRED')
    dev=path.stat().st_dev;seen=set();allocated=0;exclusive=0;count=0;stack=[path]
    while stack:
        directory=stack.pop()
        with os.scandir(directory) as entries:
            for e in entries:
                count+=1
                if count>budget:raise ValueError('INVENTORY_ENTRY_LIMIT')
                st=os.lstat(e.path)
                if st.st_dev!=dev:raise ValueError('NESTED_FILESYSTEM_REVIEW_REQUIRED')
                if stat.S_ISDIR(st.st_mode):stack.append(pathlib.Path(e.path));continue
                key=(st.st_dev,st.st_ino)
                if key in seen:continue
                seen.add(key);size=getattr(st,'st_blocks',0)*512;allocated+=size
                if stat.S_ISREG(st.st_mode) and st.st_nlink==1:exclusive+=size
    return {'allocated_file_bytes':allocated,'exclusive_regular_file_bytes_upper_bound':exclusive,'entries':count}

def overlaps(target,reference):
    t=str(target).rstrip('/');r=str(reference).replace(' (deleted)','').rstrip('/')
    return r==t or r.startswith(t+'/')

def active_users(path):
    found=set();unknown=False
    for proc in pathlib.Path('/proc').glob('[0-9]*'):
        try:
            refs=[os.readlink(proc/k) for k in ['cwd','root']]
            refs.extend(os.readlink(p) for p in (proc/'fd').iterdir())
            refs.extend(line.split(maxsplit=5)[5].strip() for line in (proc/'maps').read_text().splitlines() if len(line.split(maxsplit=5))==6)
            if any(overlaps(path,r) for r in refs):found.add(int(proc.name))
            cmd=(proc/'cmdline').read_bytes()
            if str(path).encode() in cmd:found.add(int(proc.name))
        except (FileNotFoundError,ProcessLookupError):
            if proc.exists() and int(proc.name)!=os.getpid():unknown=True
            continue
        except (PermissionError,OSError):unknown=True
    # A live mount at/under the candidate is not reclaimable by this plan.
    try:
        for line in pathlib.Path('/proc/self/mountinfo').read_text().splitlines():
            mount=line.split()[4].replace('\\040',' ')
            if overlaps(path,mount):unknown=True
    except OSError:unknown=True
    return sorted(found),unknown

def main():
    if sys.platform!='linux' or os.geteuid()!=0:raise ValueError('LINUX_ROOT_READ_ONLY_REQUIRED')
    started=time.monotonic();report=[];reclaim=0
    for work in sorted(BASE.iterdir()):
        if not PREFIX.fullmatch(work.name):continue
        if work.is_symlink() or not work.is_dir() or work.resolve()!=work or work.stat().st_uid!=0 or work.stat().st_mode&0o077:continue
        preserve=not work.name.startswith(('sulb-native-schema-','sulb-history-restore-'))
        # Preserve whole archive/backup/evidence directories. Only reproducible caches/vendor are candidates.
        row={'private_directory':str(work),'policy':'PRESERVE_ARCHIVE_BACKUP_EVIDENCE' if preserve else 'PRESERVE_EVIDENCE_REVIEW_REPRODUCIBLE_SUBDIRECTORIES','candidates':[]}
        for relative in ([] if preserve else ['source/backend/vendor','composer-cache','composer-home']):
            p=work/relative
            if not p.exists():continue
            if p.resolve()!=p or p.is_symlink() or not p.is_dir():continue
            entry={'path':str(p),'deletion_authorized':False}
            try:
                if time.monotonic()-started>90:raise ValueError('INVENTORY_TIME_LIMIT')
                entry.update(footprint(p));pids,unknown=active_users(p);entry['active_pids']=pids;entry['process_or_mount_evidence_incomplete']=unknown
                entry['review_status']='BLOCKED_ACTIVE_OR_UNPROVEN' if pids or unknown else 'STOPPED_CACHE_CANDIDATE_REQUIRES_DELETE_APPROVAL'
                entry['potential_reclaim_bytes']=0 if pids or unknown else entry['exclusive_regular_file_bytes_upper_bound'];reclaim+=entry['potential_reclaim_bytes']
            except (ValueError,OSError) as e:entry['review_status']='BLOCKED_INVENTORY_INCOMPLETE';entry['potential_reclaim_bytes']=0
            row['candidates'].append(entry)
        launch=work/'launch.json'
        if not preserve and launch.is_file() and not launch.is_symlink() and launch.stat().st_uid==0 and not launch.stat().st_mode&0o077 and launch.stat().st_size<16384:
            try:
                data=pathlib.Path(json.loads(launch.read_text())['data'])
                if re.fullmatch('/home/mysql/sulb-shipment-[a-f0-9]{32}',str(data)) and data.resolve()==data and data.is_dir() and not data.is_symlink():
                    evidence={'path':str(data),'policy':'RETAIN_ISOLATED_DATA_UNTIL_HISTORICAL_CLOSURE','deletion_authorized':False}
                    evidence.update(footprint(data));pids,unknown=active_users(data);evidence.update(active_pids=pids,process_or_mount_evidence_incomplete=unknown);row['isolated_datadir']=evidence
            except (ValueError,KeyError,OSError):row['isolated_datadir_status']='UNPROVEN_RETAIN'
        report.append(row)
    fs=os.statvfs(BASE)
    print(json.dumps({'mode':'READ_ONLY_CLOSURE_INVENTORY','free_bytes':fs.f_bavail*fs.f_frsize,'potential_reclaim_bytes_upper_bound':reclaim,'approved_delete_bytes':0,'production_directories_scanned':False,'data_directories_excluded':True,'history_archives_keys_backups_and_private_evidence_preserved':True,'no_deletion_performed':True,'inventory':report,'release_decision':'NO-GO'},indent=2))
if __name__=='__main__':
    try:main()
    except Exception:print(json.dumps({'mode':'READ_ONLY_CLOSURE_INVENTORY','status':'BLOCKED','no_deletion_performed':True,'release_decision':'NO-GO'}));sys.exit(78)
SULB_INVENTORY_PY
  exit 0
fi
if [ "${1:---plan}" = --plan ]; then
  printf '%s\n' 'NO-GO candidate. Planned order only:' \
    'GitHub -> /home/sulb-release pinned clean commit -> reviewed write fence' \
    'Candidate PHP/Composer/Next builds -> write fence -> verified private backup/restore' \
    'Explicit approved 18 migrations -> full Backend/Frontend copy -> refresh -> checks -> resume' \
    'No automatic database restore or migration rollback.'
  exit 78
fi
[ "${1:-}" = --execute ] || exit 2
stage=PHP_EXECUTABLE
trap 'rc=$?; printf "BLOCKED: stage=%s exit=%s\\n" "$stage" "$rc" >&2; if [[ -e /run/sulb-release.fence || -L /run/sulb-release.fence ]]; then printf "%s\\n" "Write fence retained. Preserve private evidence; use reviewed forward repair." >&2; fi' ERR
# An explicit runtime is mandatory; never silently inherit the default PHP CLI.
: "${SULB_PHP_BIN:?Absolute reviewed PHP CLI path required}"
test "${SULB_PHP_BIN#/}" != "$SULB_PHP_BIN"
test -x "$SULB_PHP_BIN"
runtime_php=$(realpath "$SULB_PHP_BIN")
php() { "$runtime_php" "$@"; }
# No production bootstrap or side effect is reachable before independent GO.
stage=RELEASE_APPROVAL
php "$tools/check-approval.php"
stage=RELEASE_CHECKOUT_IDENTITY
test "$(id -u)" = 0
test "$(uname -s)" = Linux
test "$(pwd -P)" = /home/sulb-release
test "$(stat -c %u /home/sulb-release)" = 0
stage=RELEASE_CHECKOUT_GROUP_OR_WORLD_WRITABLE
php -r 'if((fileperms("/home/sulb-release")&0022)!==0)exit(78);'
stage=APPROVED_CLEAN_CHECKOUT
test "$(git rev-parse HEAD)" = "${SULB_APPROVED_COMMIT:?Exact final approved commit required}"
test -z "$(git status --porcelain)"
: "${SULB_APPROVED_DERIVED_ARTIFACT:?Private SHA-bound historical plan required}"
backend=/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech
frontend=/home/mgye-sulb/htdocs/sulb.mgye.tech
stage=SITE_ACCOUNTS
id mgye-api-sulb >/dev/null
id mgye-sulb >/dev/null
backend_group=$(id -gn mgye-api-sulb)
frontend_group=$(id -gn mgye-sulb)
stage=REQUIRED_COMMANDS
for command in composer rsync systemctl tar python3 runuser realpath find curl sha256sum flock; do
  command -v "$command" >/dev/null || blocked "MISSING_COMMAND=$command"
done
# Serialize guarded executions on this host, including before acquiring the database fence.
stage=RELEASE_EXECUTION_LOCK
exec 9>/run/sulb-release.execution.lock
flock -n 9
check_previous_release
stage=FRONTEND_SERVICE_TOOLCHAIN
inspect_frontend_toolchain
stage=PHP_COMPOSER_AND_POOL_BINDING
# Check the PHP used by FPM, Composer and release entrypoints before maintenance.
php -r 'if(PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==5||PHP_SAPI!=="cli")exit(78);'
composer_file=$(realpath "$(command -v composer)")
test -f "$composer_file"
php -r '$s=file_get_contents($argv[1],false,null,0,4096);if(!is_string($s)||!str_contains($s,"<?php"))exit(78);' "$composer_file"
php -r 'require "ops/sulb-rc/release-process-fence.php";$r=json_decode(file_get_contents("ops/sulb-rc/release.json"),true,512,JSON_THROW_ON_ERROR);$r=releaseWithReviewedFencePlan($r);if(($r["write_fence_plan"]["php_fpm_unit"]??null)!=="php".PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION."-fpm.service")exit(78);'

stage=SITE_RUNTIME
php "$tools/release-process-fence.php" --inspect-site-runtime >/dev/null

# Hashes cover the entire application; binary assets are never newline-normalized.
stage=APPLICATION_SCOPE
php <<'SULB_SCOPE_PHP'
<?php
$r=json_decode(file_get_contents('ops/sulb-rc/release.json'),true,512,JSON_THROW_ON_ERROR);$f='ops/sulb-rc/full-release-scope.json';if(($r['final_closure']['scope_sha256']??null)!==hash_file('sha256',$f))exit(78);$scope=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR);foreach($scope['application_files']as$row)if(!is_file($row['path'])||is_link($row['path'])||!hash_equals($row['sha256_git_blob'],hash_file('sha256',$row['path'])))exit(78);
SULB_SCOPE_PHP
stage=PRIVATE_BUILD_DIRECTORY
backup=/home/mgye-api-sulb/sulb-release-backup.$(php -r 'echo bin2hex(random_bytes(8));')
mkdir -m 700 "$backup"
printf 'Private build and execution evidence: %s\n' "$backup"
# Root-private PATH shim also pins Python-launched PHP subprocesses.
mkdir -m 700 "$backup/runtime-bin"
printf '#!/bin/bash\nexec %q "$@"\n' "$runtime_php" > "$backup/runtime-bin/php"
chmod 700 "$backup/runtime-bin/php"
export PATH="$backup/runtime-bin:$PATH"
export SULB_CURRENT_BACKUP_DIR="$backup"
export LOG_CHANNEL=null
# Backend and privileged tools must remain root-owned and not writable by site users.
stage=PROTECTED_RELEASE_FILES
php -r '$roots=["backend","ops/sulb-rc"];foreach($roots as$root){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as$f){if(str_contains($f->getPathname(),"/storage/")||str_contains($f->getPathname(),"/vendor/"))continue;if($f->isLink()||fileowner($f->getPathname())!==0||(fileperms($f->getPathname())&0022)!==0)exit(78);}}'
if [ -d .git ]; then chmod 700 .git; else chmod 600 .git; fi
stage=BUILD_RESOURCES
test "$(awk '/MemAvailable:/{print $2}' /proc/meminfo)" -ge 4194304
test "$(df -Pk /home/mgye-api-sulb | awk 'NR==2{print $4}')" -ge 12582912
stage=FRONTEND_CHECKOUT_ACCESS
chown -R --no-dereference "mgye-sulb:$frontend_group" frontend
runuser -u mgye-sulb -- test -x /home/sulb-release/frontend
unset COMPOSER_VENDOR_DIR COMPOSER
# Composer never executes plugins/scripts; frontend tooling runs as the frontend site user.
# No live .env/storage is copied or modified by these candidate builds.
stage=CANDIDATE_BACKEND_BUILD
(cd backend && "$runtime_php" "$composer_file" check-platform-reqs --lock --no-dev && "$runtime_php" "$composer_file" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --no-plugins) > "$backup/candidate-backend-build-private.log" 2>&1
stage=CANDIDATE_FRONTEND_BUILD
(cd frontend; frontend_run "$frontend_npm" ci --include=dev --no-audit --no-fund; frontend_run "$frontend_npm" run build) > "$backup/candidate-frontend-build-private.log" 2>&1
stage=CANDIDATE_INTEGRITY
test -z "$(git status --porcelain)"
# Dependency platform checks use the actual installed vendor, not config.platform.
(cd backend && "$runtime_php" "$composer_file" check-platform-reqs --no-dev) > "$backup/candidate-platform-private.log" 2>&1
php "$tools/release-app-control.php" --prepare-candidate > "$backup/candidate-manifest-private.json"
check_previous_release
stage=ACQUIRE_WRITE_FENCE
php "$tools/release-fence.php" --acquire "$backup" > "$backup/fence-summary.json"
export SULB_RELEASE_PERMIT_SHA256=$(sha256sum /run/sulb-release.fence/permit.json | cut -d ' ' -f1)
# Password comes only from /dev/tty. Source reader is SELECT-only sulbmetadata.
stage=ENCRYPTED_BACKUP
php "$tools/release-backup.php" --capture "$backup" > "$backup/backup-summary.json"
stage=ISOLATED_RESTORE
python3 "$tools/history-stage.py" --verify-release-backup "$backup" > "$backup/restore-summary.json"
stage=UPGRADE_RECEIPT
php "$tools/release-backup.php" --issue-upgrade-receipt "$backup" > "$backup/receipt-summary.json"
export SULB_UPGRADE_AUTHORIZATION_FILE="$backup/upgrade-authorization.json"
# DDL runs from immutable candidate code while all customer writers remain fenced.
# Failure retains the fence; never auto-restore an older database or run down().
stage=ORDERED_UPGRADE
php "$tools/production-upgrade.php" --execute > "$backup/migration-summary.json"
stage=APPLICATION_COPY
for dir in "$backend" "$frontend" "$backend/bootstrap/cache"; do test ! -L "$dir"; test "$(realpath -m "$dir")" = "$dir"; done
# No application copy occurs until the actual backup has been restored and financial drift rejected.
php -r '$f=fopen($argv[1],"xb");if(!$f||!fwrite($f,"COPY_STARTED\n")||!fflush($f)||!fsync($f))exit(78);fclose($f);' "$backup/application-copy-started"
rsync -a --chown="mgye-api-sulb:$backend_group" --chmod=Du=rwx,Dgo=rx,Fu=rwX,Fgo=rX --exclude='.git/' --exclude='.env*' --exclude='storage/' --exclude='bootstrap/cache/' --exclude='vendor/' --exclude='tests/' --exclude='public/storage' --exclude='public/uploads/' backend/ "$backend/"
rsync -a --chown="mgye-sulb:$frontend_group" --chmod=Du=rwx,Dgo=rx,Fu=rwX,Fgo=rX --exclude='.git/' --exclude='.env*' --exclude='.next*/' --exclude='node_modules/' --exclude='next-env.d.ts' --exclude='public/uploads/' frontend/ "$frontend/"
# --delete is restricted to exact generated dependency/build directories, never application data.
for dir in "$backend/vendor" "$frontend/node_modules" "$frontend/.next"; do
  test ! -L "$dir"
  test "$(realpath -m "$dir")" = "$dir"
done
rsync -a --delete --chown="mgye-api-sulb:$backend_group" --chmod=Du=rwx,Dgo=rx,Fu=rwX,Fgo=rX backend/vendor/ "$backend/vendor/"
rsync -a --delete --chown="mgye-sulb:$frontend_group" --chmod=Du=rwx,Dgo=rx,Fu=rwX,Fgo=rX frontend/node_modules/ "$frontend/node_modules/"
rsync -a --delete --chown="mgye-sulb:$frontend_group" --chmod=Du=rwx,Dgo=rx,Fu=rwX,Fgo=rX frontend/.next/ "$frontend/.next/"
stage=PUBLISH_APPLICATION_CACHE
php "$tools/release-app-control.php" --publish-cache > "$backup/cache-publish-private.json"
chown -R "mgye-api-sulb:$backend_group" "$backend/bootstrap/cache"
find "$backend/bootstrap/cache" -type d -exec chmod 755 {} +
find "$backend/bootstrap/cache" -type f -exec chmod 644 {} +
php "$tools/release-fence.php" --refresh-workers "$backup" > "$backup/refresh-summary.json"
stage=RELEASE_POSTCHECK
php "$tools/release-postcheck.php" > "$backup/postcheck-summary.json"
stage=RELEASE_ACTIVATION
php "$tools/release-activate.php" > "$backup/activation-summary.json"
php "$tools/release-fence.php" --refresh-workers "$backup" > "$backup/activated-workers-summary.json"
# Start only SULB Frontend while database/app writers remain fenced.
stage=FRONTEND_RESTART
systemctl restart sulb-frontend.service
stage=FENCED_HTTP_READINESS
php "$tools/release-fence.php" --verify-readiness "$backup" > "$backup/operational-readiness-summary.json"
# Last state-changing action: no service starts, curls or worker resumes after unlock.
stage=RELEASE_WRITE_FENCE
php "$tools/release-fence.php" --release "$backup" > "$backup/release-summary.json"
printf '%s\n' "Full Backend+Frontend+18 migrations completed. Private backup/evidence: $backup"
