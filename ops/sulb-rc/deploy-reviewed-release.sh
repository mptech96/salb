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
# This candidate ALWAYS stops here. Only a separately reviewed approval commit
# with all evidence, exact production baseline and migration executor may proceed.
php "$tools/check-approval.php"
# Capture the approved list in the parent shell: process-substitution failures
# must not turn into an empty successful migration loop.
approved_migrations=$(php "$tools/check-approval.php" --migrations)
test -n "$approved_migrations"
test "$(pwd -P)" = /home/sulb-release
test "$(git rev-parse HEAD)" = "${SULB_APPROVED_COMMIT:?Pinned approved commit required}"
test -z "$(git status --porcelain)"
: "${SULB_BACKUP_CNF:?Protected dedicated backup-client config required}"
: "${SULB_UPGRADE_AUTHORIZATION_FILE:?Qualified source-bound upgrade receipt required}"
: "${SULB_WRITE_FENCE_RECEIPT:?Reviewed web/queue/import write-fence evidence required}"
test -s "$SULB_WRITE_FENCE_RECEIPT"
test "$(stat -c %a "$SULB_BACKUP_CNF")" = 600
test "$(stat -c %u "$SULB_BACKUP_CNF")" = "$(id -u)"
# Use the explicitly approved existing account in the private client file;
# never assume that a new sulb_backup_ro account has been created.
php -d display_errors=0 -d log_errors=0 -r '$c=@parse_ini_file($argv[1],true,INI_SCANNER_RAW);$u=$c["mysqldump"]["user"]??$c["client"]["user"]??null;if(!is_string($u)||trim($u)==="")exit(78);' "$SULB_BACKUP_CNF"
backend=/home/mgye-api-sulb/htdocs/api.sulb.mgye.tech
frontend=/home/mgye-sulb/htdocs/sulb.mgye.tech
backup=$(mktemp -d /home/mgye-api-sulb/sulb-release-backup.XXXXXXXX)
php "$tools/preflight.php" > "$backup/preflight.json"
php -r '$m=json_decode(file_get_contents($argv[1]),true);if(($m["schema_matches_approved_baseline"]??false)!==true)exit(78);' "$backup/preflight.json"
dump=mysqldump
command -v "$dump" >/dev/null || { printf 'STOP: matching MySQL/Percona8.4 dump client required.\n' >&2; exit 78; }
dump_version=$("$dump" --version)
[[ "$dump_version" =~ Ver[[:space:]]8\.4\. ]] || { printf 'STOP: dump client is not qualified for Percona8.4.\n' >&2; exit 78; }
"$dump" --defaults-extra-file="$SULB_BACKUP_CNF" --single-transaction --no-tablespaces --set-gtid-purged=OFF --routines --triggers --events --hex-blob sulbprod > "$backup/database.sql"
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
php "$backend/artisan" config:clear
export SULB_CURRENT_BACKUP_DIR="$backup"
php "$tools/production-upgrade.php" --execute
(cd "$frontend" && npm ci && npm run build)
systemctl restart sulb-frontend.service
php "$backend/artisan" up
curl --fail --silent --show-error https://api.sulb.mgye.tech/up >/dev/null
curl --fail --silent --show-error https://sulb.mgye.tech/ >/dev/null
printf 'HTTP smoke passed; business UAT and authorized write-fence release still required.\n'
