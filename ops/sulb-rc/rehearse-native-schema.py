"""Explicitly approved Schema-only execution. Never uses a host DB connection."""
import contextlib, hashlib, importlib.util, io, json, os, pathlib, re, shutil, signal, stat, subprocess, sys, tarfile, tempfile, time, uuid
from native_isolation_checks import CheckBlocked, exact_socket_plan, exception_evidence
from native_rehearsal_resources import OwnedGroup
spec=importlib.util.spec_from_file_location('isolation',pathlib.Path(__file__).with_name('close-native-isolation.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
SHA='901f01f5069078cc1174f5bdd913609abbf72f2cadab9852cff05133a5a9047f'
CAPTURE='/home/mgye-api-sulb/sulb-private-schema-88b4a61422be64ba8a53bdee/schema-private.json'
BASE=pathlib.Path('/home/mgye-api-sulb')

def safe_log_codes(path):
    if not pathlib.Path(path).is_file():return {}
    with open(path,'rb') as log:
        log.seek(max(0,pathlib.Path(path).stat().st_size-1048576));text=log.read(1048576).decode(errors='replace')
    allowed={'REHEARSAL_ARGUMENTS_REQUIRED','NATIVE_PRIVATE_CONTEXT_REQUIRED','OWNED_SOCKET_REQUIRED','PRIVATE_NETWORK_NAMESPACE_REQUIRED','OFFLINE_PERCONA_REQUIRED','EMPTY_INSTANCE_REQUIRED','OWNED_NAME_REQUIRED','NO_ENV_OR_CONFIG_CACHE_ALLOWED','SCHEMA_REHEARSAL_FAILED','PROOF_WRITE_FAILED','RUNTIME_FAILURE'}
    codes=[c for c in re.findall(r'\bSTOP_CODE=([A-Z0-9_]+)',text) if c in allowed or re.fullmatch(r'PDO_FAILURE_[A-Z0-9]{5}_\d{1,5}',c)]
    return {'stop_codes':list(dict.fromkeys(codes))[-12:],
            'mysql_error_ids':list(dict.fromkeys(re.findall(r'\bMY-\d{6}\b',text)))[-12:],
            'sqlstates':list(dict.fromkeys(re.findall(r'SQLSTATE\[([A-Z0-9]{5})\]',text)))[-12:],
            'known_failure_categories':[x for x in ['Allowed memory size','Permission denied','Could not resolve host','SSL certificate problem','Resource temporarily unavailable'] if x in text]}

LIVE=['/home/mgye-api-sulb/htdocs','/home/mgye-sulb/htdocs','/etc/mysql','/root']

def identity(path):
    s=os.stat(path);return [s.st_dev,s.st_ino]
def clean_env(home):
    return {'PATH':'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','HOME':str(home),'LC_ALL':'C','TMPDIR':str(home)}
def checked_capture(path):
    path=pathlib.Path(path)
    if str(path)!=CAPTURE or path.is_symlink() or not path.is_file():raise CheckBlocked('PINNED_CAPTURE_PATH_REQUIRED')
    if path.stat().st_size>16777216:raise CheckBlocked('CAPTURE_SIZE_LIMIT')
    raw=path.read_bytes()
    if hashlib.sha256(raw).hexdigest()!=SHA:raise CheckBlocked('PINNED_CAPTURE_HASH_MISMATCH')
    j=json.loads(raw)
    if j.get('mode')!='PRIVATE_SCHEMA_ONLY' or j.get('business_rows_exported') is not False:raise CheckBlocked('SCHEMA_ONLY_CAPTURE_REQUIRED')
    counts={k:0 for k in ['TABLE','VIEW','TRIGGER','PROCEDURE','FUNCTION','EVENT']}
    for o in j['definitions']:
        if o['kind'] not in counts:raise CheckBlocked('UNREVIEWED_OBJECT_KIND')
        counts[o['kind']]+=1
    if counts!={'TABLE':111,'VIEW':0,'TRIGGER':0,'PROCEDURE':0,'FUNCTION':0,'EVENT':0}:raise CheckBlocked('REVIEWED_CAPTURE_COUNTS_CHANGED')
    return raw

def archive_source(root,work,group):
    target=work/'source';target.mkdir(mode=0o700)
    archive=work/'source.tar'
    subprocess.run(group.wrap(['git','-c','safe.directory='+str(root),'-C',str(root),'archive','--output='+str(archive),'HEAD','backend','ops/sulb-rc','docs/erp-baseline/proposed-migrations']),check=True,timeout=120,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=clean_env(work))
    with tarfile.open(archive) as tar:
        for member in tar.getmembers():
            parts=pathlib.PurePosixPath(member.name).parts
            if member.name.startswith('/') or '..' in parts or not(member.isfile() or member.isdir()):raise CheckBlocked('SOURCE_ARCHIVE_MEMBER_REFUSED')
            if any(p.startswith('.env') and p!='.env.example' for p in parts) or 'vendor' in parts:raise CheckBlocked('SOURCE_CONTAINS_ENV_OR_VENDOR')
            dest=target.joinpath(*parts)
            if member.isdir():dest.mkdir(parents=True,exist_ok=True)
            else:
                dest.parent.mkdir(parents=True,exist_ok=True)
                with tar.extractfile(member) as src,open(dest,'xb') as out:shutil.copyfileobj(src,out)
    archive.unlink()
    if any(p.name!='.gitignore' for p in (target/'backend/bootstrap/cache').glob('*')):raise CheckBlocked('SOURCE_CONFIG_CACHE_REFUSED')
    return target

def private_command(args,log,timeout,env,cwd=None):
    with open(log,'ab') as output:
        p=subprocess.Popen(args,stdout=output,stderr=output,stdin=subprocess.DEVNULL,env=env,cwd=cwd,start_new_session=True)
        try:
            code=p.wait(timeout=timeout)
            if code:raise CheckBlocked('OWNED_COMMAND_FAILED',{'exit_code':code,'log_name':pathlib.Path(log).name})
        except BaseException:
            if p.poll() is None:
                os.killpg(p.pid,signal.SIGTERM)
                try:p.wait(timeout=10)
                except subprocess.TimeoutExpired:os.killpg(p.pid,signal.SIGKILL);p.wait(timeout=10)
            raise

def mysql_args(datadir,sock):
    if not re.fullmatch(r'/(home/mysql|var/lib/mysql)/sulb-shipment-[a-f0-9]{32}',datadir):raise CheckBlocked('OWNED_DATADIR_REQUIRED')
    return ['/usr/sbin/mysqld','--no-defaults','--datadir='+datadir,'--socket='+sock,'--pid-file='+datadir+'/owned.pid',
      '--log-error='+datadir+'/owned-error.log','--tmpdir='+datadir,'--skip-networking','--mysqlx=0','--skip-log-bin',
      '--performance-schema=OFF','--innodb-buffer-pool-size=32M','--innodb-log-buffer-size=8M','--innodb-redo-log-capacity=64M',
      '--innodb-log-writer-threads=OFF','--innodb-parallel-read-threads=1','--innodb-read-io-threads=1','--innodb-write-io-threads=1','--innodb-purge-threads=1','--max-connections=5',
      '--table-open-cache=128','--table-open-cache-instances=1','--thread-cache-size=0','--temptable-max-ram=16M',
      '--restrict-fk-on-non-standard-key=ON','--local-infile=OFF','--secure-file-priv=NULL','--event-scheduler=OFF']

def rehearsal_command(source,work,sock,datadir,contextfile):
    return ['php','-d','memory_limit=128M','-d','display_errors=0','-d','log_errors=0',str(source/'ops/sulb-rc/run-private-socket-rehearsal.php'),sock,datadir,str(work/'schema-private.json'),SHA,str(work/'migration-result.json'),str(contextfile)]

def native_child(work):
    os.umask(0o077)
    work=pathlib.Path(work);specification=json.loads((work/'launch.json').read_text())
    data=specification['data'];sockets=specification['sockets'];logical=specification['logical'];sock=specification['socket']
    result={'stage':'MASK_PRIVATE_FILESYSTEM','schema_only_rehearsal':'BLOCKED','release_decision':'NO-GO','production_connected':False,'business_rows_imported':False,'mysqld_started':False}
    server=None
    try:
        # Refuse direct invocation on the host BEFORE the first mount operation.
        if os.getpid()!=1:raise CheckBlocked('PRIVATE_PID1_REQUIRED_BEFORE_MOUNTS')
        if os.readlink('/proc/self/ns/net')==specification['host_net'] or os.readlink('/proc/self/ns/pid')==specification['host_pid']:raise CheckBlocked('PRIVATE_NAMESPACE_REQUIRED_BEFORE_MOUNTS')
        if pathlib.Path('/proc/self/cgroup').read_text().strip()!='0::/'+specification['cgroup_name']:raise CheckBlocked('OWNED_CGROUP_ATTACHMENT_REQUIRED')
        with contextlib.redirect_stdout(io.StringIO()):m.namespace_child(str(work),data,sockets,logical,os.path.dirname(sock))
        if pathlib.Path('/proc/self/cgroup').read_text().strip()!='0::/'+specification['cgroup_name']:raise CheckBlocked('OWNED_CGROUP_ATTACHMENT_REQUIRED')
        # Hide live application secrets and administrative defaults in this child only.
        for i,path in enumerate(LIVE):
            if pathlib.Path(path).is_dir():
                empty=work/('private-hide-'+str(i));empty.mkdir(mode=0o700)
                m.run(['mount','--bind',str(empty),path])
        if os.readlink('/proc/self/ns/net')==specification['host_net'] or os.readlink('/proc/self/ns/pid')==specification['host_pid']:raise CheckBlocked('PRIVATE_NAMESPACE_CHANGED')
        import pwd
        owner=pwd.getpwnam('mysql')
        # All of these are already private backing folders, never host mounts.
        os.chmod(logical,0o755)
        os.chown(os.path.dirname(sock),owner.pw_uid,owner.pw_gid);os.chmod(os.path.dirname(sock),0o700)
        datadir=logical+'/sulb-shipment-'+specification['token']
        pathlib.Path(datadir).mkdir(mode=0o700);os.chown(datadir,owner.pw_uid,owner.pw_gid)
        for p in [sock,sock+'.lock']:
            if os.path.lexists(p):raise CheckBlocked('PRIVATE_SOCKET_TARGET_NOT_EMPTY')
        args=mysql_args(datadir,sock)
        drop=['setpriv','--reuid='+str(owner.pw_uid),'--regid='+str(owner.pw_gid),'--clear-groups','--bounding-set=-all','--inh-caps=-all','--ambient-caps=-all','--no-new-privs','--']
        env=clean_env(datadir)
        result.update(stage='INITIALIZE_OWNED_PERCONA',mysqld_started=True)
        private_command(drop+args+['--initialize-insecure'],work/'initialize-private.log',900,env)
        result['stage']='START_OWNED_PERCONA'
        with open(work/'server-private.log','ab') as log:
            server=subprocess.Popen(drop+args,stdin=subprocess.DEVNULL,stdout=log,stderr=log,env=env)
        ready=False
        probe=['php','-d','display_errors=0','-d','log_errors=0','-r','try { new PDO("mysql:unix_socket=".$argv[1],"root",""); } catch(Throwable $e) {exit(1);}',sock]
        for _ in range(120):
            if server.poll() is not None:raise CheckBlocked('OWNED_SERVER_EXITED',{'exit_code':server.returncode})
            if pathlib.Path(sock).exists():
                if pathlib.Path('/proc/'+str(server.pid)+'/attr/current').read_text().strip()!=specification['label']:raise CheckBlocked('OWNED_SERVER_APPARMOR_LABEL_MISMATCH')
                if subprocess.run(probe,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=clean_env(work),timeout=5).returncode==0:ready=True;break
            time.sleep(1)
        if not ready:raise CheckBlocked('OWNED_SERVER_READINESS_TIMEOUT')
        context={'mode':'NATIVE_SCHEMA_ONLY','production_connected':False,'datadir':datadir,'socket':sock,'socket_identity':identity(sock),'server_pid':server.pid,
                 'host_net':specification['host_net'],'host_pid':specification['host_pid'],'private_net':os.readlink('/proc/self/ns/net'),'private_pid':os.readlink('/proc/self/ns/pid'),'masking_complete':True,'limits_enforced':True}
        contextfile=work/'native-context.json';contextfile.write_text(json.dumps(context));contextfile.chmod(0o600)
        result['stage']='ORDERED_SCHEMA_MIGRATIONS'
        source=work/'source'
        private_command(rehearsal_command(source,work,sock,datadir,contextfile),work/'migration-private.log',1800,clean_env(work))
        proof=json.loads((work/'migration-result.json').read_text())
        if proof.get('schema_only_rehearsal')!='PASS':raise CheckBlocked('REHEARSAL_RESULT_NOT_PASS')
        result.update(proof);result['stage']='COMPLETE'
    except Exception as e:
        result.update(schema_only_rehearsal='BLOCKED',blocker=e.code if isinstance(e,CheckBlocked) else 'NATIVE_CHILD_RUNTIME_FAILURE',failure=exception_evidence(result['stage'],e))
        if isinstance(e,CheckBlocked):result['failure'].update(e.evidence)
        result['migration_diagnostics']=safe_log_codes(work/'migration-private.log')
        if 'datadir' in locals():result['server_diagnostics']=safe_log_codes(pathlib.Path(datadir)/'owned-error.log')
        progress=work/'migration-progress.json'
        if progress.is_file():
            step=json.loads(progress.read_text()).get('step','UNKNOWN')
            if re.fullmatch(r'(schema_import|schema_assertions|uom:\d{3}|dependency:[a-z_]+\.php|complete)',step):result['failed_step']=step
    finally:
        if server is not None:
            if server.poll() is None:
                server.terminate()
                try:server.wait(timeout=60)
                except subprocess.TimeoutExpired:server.kill();server.wait(timeout=10);result.update(schema_only_rehearsal='BLOCKED',blocker='OWNED_SERVER_REQUIRED_FORCED_STOP')
            result['owned_server_stopped']=server.poll() is not None
        (work/'child-summary.json').write_text(json.dumps(result,indent=2));(work/'child-summary.json').chmod(0o600)
    return 0 if result['schema_only_rehearsal']=='PASS' else 78

def main(execute=False):
    result={'schema_only_rehearsal':'BLOCKED','release_decision':'NO-GO','production_connected':False,'business_rows_imported':False,'mysqld_started':False,'prior_isolation_receipt':'c925a5963339cf40d75c61a251a66e244ca3da75: NATIVE_STAGING_GO','existing_cgroups_modified':False}
    group=None;work=None;before=None;identities={};phase='EXECUTION_APPROVAL'
    try:
        os.umask(0o077)
        if not execute:raise CheckBlocked('EXPLICIT_EXECUTE_SCHEMA_ONLY_REQUIRED')
        if sys.platform!='linux' or os.geteuid()!=0:raise CheckBlocked('LINUX_ROOT_REQUIRED')
        phase='PINNED_PRIVATE_CAPTURE';raw=checked_capture(CAPTURE)
        phase='LAUNCH_CONTEXT';before=m.process_state();data,sockets,_=m.production_paths(before[0])
        if data!='/home/mysql':raise CheckBlocked('APPROVED_DATADIR_MAPPING_CHANGED')
        identities={p:identity(p) for p in [data,'/var/lib','/run']+sockets}
        if os.path.realpath('/proc/'+str(before[0])+'/exe')!='/usr/sbin/mysqld':raise CheckBlocked('APPROVED_SERVER_EXECUTABLE_CHANGED')
        token=uuid.uuid4().hex;plan=exact_socket_plan(before[1],data,sockets,token)
        # Permission selection at launch only; no namespace or cgroup benchmarks rerun.
        phase='OWNED_WORKSPACE'
        if shutil.disk_usage(BASE).free<4*1024**3:raise CheckBlocked('INSUFFICIENT_OWNED_STAGING_DISK_HEADROOM')
        work=pathlib.Path(tempfile.mkdtemp(prefix='sulb-native-schema-',dir=str(BASE)));work.chmod(0o700)
        result['private_work_directory']=str(work)
        (work/'schema-private.json').write_bytes(raw);(work/'schema-private.json').chmod(0o600)
        phase='ENFORCE_APPROVED_LIMITS';group=OwnedGroup(work);group.create();result['enforced_limits']=group.controls
        root=pathlib.Path(__file__).resolve().parents[2]
        phase='ARCHIVE_TRACKED_SOURCE';source=archive_source(root,work,group)
        phase='ISOLATED_COMPOSER_INSTALL';print('STAGE='+phase,flush=True)
        for name in ['composer-home','composer-cache']:(work/name).mkdir(mode=0o700)
        env=clean_env(work);env.update(COMPOSER_ALLOW_SUPERUSER='1',COMPOSER_HOME=str(work/'composer-home'),COMPOSER_CACHE_DIR=str(work/'composer-cache'),COMPOSER_MAX_PARALLEL_HTTP='2',COMPOSER_MAX_PARALLEL_PROCESSES='1')
        private_command(group.wrap(['composer','--working-dir='+str(source/'backend'),'install','--no-interaction','--prefer-dist','--no-progress','--no-scripts','--no-plugins']),work/'dependencies-private.log',1800,env)
        launch={'data':data,'sockets':sockets,'logical':plan['selected_logical_datadir'],'socket':plan['selected_socket_path'],'token':token,'label':before[1],'cgroup_name':group.path.name,
                'host_net':os.readlink('/proc/self/ns/net'),'host_pid':os.readlink('/proc/self/ns/pid')}
        (work/'launch.json').write_text(json.dumps(launch));(work/'launch.json').chmod(0o600)
        phase='NATIVE_SCHEMA_ONLY_EXECUTION';print('STAGE='+phase,flush=True)
        try:
            private_command(group.wrap(['unshare','--mount','--net','--pid','--fork',sys.executable,str(source/'ops/sulb-rc/rehearse-native-schema.py'),'--native-child',str(work)]),work/'namespace-private.log',3000,clean_env(work))
        except CheckBlocked:
            if not (work/'child-summary.json').is_file():raise
        if not (work/'child-summary.json').is_file():raise CheckBlocked('PRIVATE_CHILD_RESULT_MISSING')
        result.update(json.loads((work/'child-summary.json').read_text()))
    except Exception as e:
        result.update(schema_only_rehearsal='BLOCKED',blocker=e.code if isinstance(e,CheckBlocked) else 'NATIVE_REHEARSAL_RUNTIME_FAILURE',failure=exception_evidence(phase,e))
        if isinstance(e,CheckBlocked):result['failure'].update(e.evidence)
        if group is not None:result['resource_phase']=group.phase
        if work is not None:result['dependency_diagnostics']=safe_log_codes(work/'dependencies-private.log')
    finally:
        if group is not None:
            try:
                if group.created:
                    result['memory_events']=(group.path/'memory.events').read_text().strip().splitlines()
                    result['pids_events']=(group.path/'pids.events').read_text().strip().splitlines()
                group.cleanup();result['owned_cgroup_removed']=True
            except Exception as e:result.update(schema_only_rehearsal='BLOCKED',cleanup_blocker='OWNED_CGROUP_CLEANUP_FAILED')
        if before is not None:
            try:
                if m.process_state()!=before or any(identity(p)!=v for p,v in identities.items()):raise CheckBlocked('HOST_PRODUCTION_IDENTITY_CHANGED')
                result['host_production_identities_unchanged']=True
            except Exception:result.update(schema_only_rehearsal='BLOCKED',host_verification='HOST_PRODUCTION_IDENTITY_NOT_CONFIRMED')
        if work is not None:
            (work/'safe-summary.json').write_text(json.dumps(result,indent=2));(work/'safe-summary.json').chmod(0o600)
    print(json.dumps(result,indent=2));return 0 if result['schema_only_rehearsal']=='PASS' else 78

if __name__=='__main__':
    if len(sys.argv)==3 and sys.argv[1]=='--native-child':sys.exit(native_child(sys.argv[2]))
    sys.exit(main(sys.argv[1:]==['--execute-schema-only']))
