#!/usr/bin/env bash
set -euo pipefail
umask 077
tools=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
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
    'Private code/data backup -> rsync Backend/Frontend -> Composer' \
    'Explicit approved migrations -> Frontend build -> restart -> smoke tests' \
    'No automatic database restore or migration rollback.'
  exit 78
fi
[ "${1:-}" = --execute ] || exit 2
# No production bootstrap or side effect is reachable before independent GO.
php "$tools/check-approval.php"
test "$(id -u)" = 0
test "$(uname -s)" = Linux
test "$(pwd -P)" = /home/sulb-release
test "$(stat -c %u /home/sulb-release)" = 0
php -r 'if((fileperms("/home/sulb-release")&0022)!==0)exit(78);'
test "$(git rev-parse HEAD)" = "${SULB_APPROVED_COMMIT:?Exact final approved commit required}"
test -z "$(git status --porcelain)"
: "${SULB_APPROVED_DERIVED_ARTIFACT:?Private SHA-bound historical plan required}"
backend=/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech
frontend=/home/mgye-sulb/htdocs/sulb.mgye.tech
id mgye-api-sulb >/dev/null
id mgye-sulb >/dev/null
backend_group=$(id -gn mgye-api-sulb)
frontend_group=$(id -gn mgye-sulb)
for command in php composer node npm rsync systemctl tar python3 runuser realpath find; do command -v "$command" >/dev/null; done
# Hashes cover the entire application; binary assets are never newline-normalized.
php <<'SULB_SCOPE_PHP'
<?php
$r=json_decode(file_get_contents('ops/sulb-rc/release.json'),true,512,JSON_THROW_ON_ERROR);$f='ops/sulb-rc/full-release-scope.json';if(($r['final_closure']['scope_sha256']??null)!==hash_file('sha256',$f))exit(78);$scope=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR);foreach($scope['application_files']as$row)if(!is_file($row['path'])||is_link($row['path'])||!hash_equals($row['sha256_git_blob'],hash_file('sha256',$row['path'])))exit(78);
SULB_SCOPE_PHP
backup=/home/mgye-api-sulb/sulb-release-backup.$(php -r 'echo bin2hex(random_bytes(8));')
mkdir -m 700 "$backup"
export SULB_CURRENT_BACKUP_DIR="$backup"
export LOG_CHANNEL=null
# Backend and privileged tools must remain root-owned and not writable by site users.
php -r '$roots=["backend","ops/sulb-rc"];foreach($roots as$root){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as$f){if(str_contains($f->getPathname(),"/storage/")||str_contains($f->getPathname(),"/vendor/"))continue;if($f->isLink()||fileowner($f->getPathname())!==0||(fileperms($f->getPathname())&0022)!==0)exit(78);}}'
if [ -d .git ]; then chmod 700 .git; else chmod 600 .git; fi
test "$(systemctl show sulb-frontend.service --property=User --value)" = mgye-sulb
# Install build-time frontend tools explicitly; do not run npm as root.
node -e 'const [a,b]=process.versions.node.split(".").map(Number);if(a<20||(a===20&&b<9))process.exit(78)'
test "$(awk '/MemAvailable:/{print $2}' /proc/meminfo)" -ge 4194304
test "$(df -Pk /home/mgye-api-sulb | awk 'NR==2{print $4}')" -ge 12582912
chown -R --no-dereference "mgye-sulb:$frontend_group" frontend
runuser -u mgye-sulb -- test -x /home/sulb-release/frontend
unset COMPOSER_VENDOR_DIR COMPOSER
# Composer never executes plugins/scripts; frontend tooling runs as the frontend site user.
# No live .env/storage is copied or modified by these candidate builds.
(cd backend && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts --no-plugins) > "$backup/candidate-backend-build-private.log" 2>&1
(cd frontend && runuser -u mgye-sulb -- env -i PATH="$PATH" HOME=/home/mgye-sulb NODE_ENV=production SULB_QA_UOM=0 NEXT_PUBLIC_API_URL=https://api.sulb.mgye.tech/api NODE_OPTIONS=--max-old-space-size=2048 npm ci --include=dev --no-audit --no-fund && runuser -u mgye-sulb -- env -i PATH="$PATH" HOME=/home/mgye-sulb NODE_ENV=production SULB_QA_UOM=0 NEXT_PUBLIC_API_URL=https://api.sulb.mgye.tech/api NODE_OPTIONS=--max-old-space-size=2048 npm run build) > "$backup/candidate-frontend-build-private.log" 2>&1
test -z "$(git status --porcelain)"
php "$tools/release-app-control.php" --prepare-candidate > "$backup/candidate-manifest-private.json"
trap 'if [ -d /run/sulb-release.fence ]; then printf "%s\n" "BLOCKED: write fence retained; preserve all private evidence and obtain reviewed forward repair." >&2; else printf "%s\n" "BLOCKED after fence release or before acquisition: customer writes may be active. Preserve evidence; never restore an older database or run down()." >&2; fi' ERR
php "$tools/release-fence.php" --acquire "$backup" > "$backup/fence-summary.json"
export SULB_RELEASE_PERMIT_SHA256=$(sha256sum /run/sulb-release.fence/permit.json | cut -d ' ' -f1)
# Password comes only from /dev/tty. Source reader is SELECT-only sulbmetadata.
php "$tools/release-backup.php" --capture "$backup" > "$backup/backup-summary.json"
python3 "$tools/history-stage.py" --verify-release-backup "$backup" > "$backup/restore-summary.json"
php "$tools/release-backup.php" --issue-upgrade-receipt "$backup" > "$backup/receipt-summary.json"
export SULB_UPGRADE_AUTHORIZATION_FILE="$backup/upgrade-authorization.json"
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
php "$tools/release-app-control.php" --publish-cache > "$backup/cache-publish-private.json"
chown -R "mgye-api-sulb:$backend_group" "$backend/bootstrap/cache"
find "$backend/bootstrap/cache" -type d -exec chmod 755 {} +
find "$backend/bootstrap/cache" -type f -exec chmod 644 {} +
php "$tools/release-fence.php" --refresh-workers "$backup" > "$backup/refresh-summary.json"
php "$tools/production-upgrade.php" --execute > "$backup/migration-summary.json"
php "$tools/release-postcheck.php" > "$backup/postcheck-summary.json"
php "$tools/release-activate.php" > "$backup/activation-summary.json"
php "$tools/release-fence.php" --refresh-workers "$backup" > "$backup/activated-workers-summary.json"
php "$tools/release-fence.php" --release "$backup" > "$backup/release-summary.json"
php "$tools/release-fence.php" --resume-workers "$backup" > "$backup/resume-workers-summary.json"
systemctl restart sulb-frontend.service
curl --fail --silent --show-error https://api.sulb.mgye.tech/up >/dev/null
curl --fail --silent --show-error https://sulb.mgye.tech/ >/dev/null
printf '%s\n' "Full Backend+Frontend+18 migrations completed. Private backup/evidence: $backup"
