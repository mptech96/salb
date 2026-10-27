"""History readiness now; row capture is blocked until separate release approval."""
import contextlib, importlib.util, io, json, os, pathlib, re, shutil, subprocess, sys, time, uuid
from native_isolation_checks import CheckBlocked, exact_socket_plan, exception_evidence
from native_rehearsal_resources import OwnedGroup
spec=importlib.util.spec_from_file_location('base',pathlib.Path(__file__).with_name('rehearse-native-schema.py'))
b=importlib.util.module_from_spec(spec);spec.loader.exec_module(b)
BASE=pathlib.Path('/home/mgye-api-sulb')

def secure_directory(path,prefix):
    p=pathlib.Path(path)
    if not re.fullmatch(re.escape(str(BASE)+'/'+prefix)+r'[a-f0-9]{16}',str(p)) or p.is_symlink() or p.resolve()!=p or not p.is_dir() or p.stat().st_uid!=0 or p.stat().st_mode&0o077:raise CheckBlocked('HISTORY_PRIVATE_DIRECTORY_REQUIRED')
    return p

def disable_core_dumps():
    import resource
    resource.setrlimit(resource.RLIMIT_CORE,(0,0))

def collector(mode,work,group):
    import termios
    # Keep the controlling TTY for hidden input; never pass a password in argv/env.
    fd=os.open('/dev/tty',os.O_RDWR);saved=termios.tcgetattr(fd)
    try:
        cmd=group.wrap(['php','-d','memory_limit=128M','-d','display_errors=0','-d','log_errors=0',str(pathlib.Path(__file__).with_name('history-capture.php')),mode,str(work)])
        with open(work/'collector-private.log','ab') as error:
            p=subprocess.run(cmd,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=error,env=b.clean_env(work),timeout=3900 if mode=='--capture-approved' else 180)
        result=json.loads(p.stdout)
        if not isinstance(result,dict):raise CheckBlocked('HISTORY_COLLECTOR_RESULT_INVALID')
        if p.returncode:result['history_status']='BLOCKED'
        return result
    finally:
        termios.tcsetattr(fd,termios.TCSANOW,saved);os.close(fd)

def restore_child(work):
    work=pathlib.Path(work);s=json.loads((work/'launch.json').read_text());server=None
    result={'snapshot_restore_rehearsal':'BLOCKED','stage':'PRIVATE_MOUNTS','production_connected':False,'release_decision':'NO-GO'}
    try:
        if os.getpid()!=1 or os.readlink('/proc/self/ns/net')==s['host_net'] or os.readlink('/proc/self/ns/pid')==s['host_pid']:raise CheckBlocked('HISTORY_PRIVATE_NAMESPACE_REQUIRED')
        if pathlib.Path('/proc/self/cgroup').read_text().strip()!='0::/'+s['cgroup_name']:raise CheckBlocked('HISTORY_OWNED_CGROUP_REQUIRED')
        with contextlib.redirect_stdout(io.StringIO()):b.m.namespace_child(str(work),s['data'],s['sockets'],s['logical'],os.path.dirname(s['socket']))
        for i,path in enumerate(b.LIVE):
            if pathlib.Path(path).is_dir():
                empty=work/('history-hide-'+str(i));empty.mkdir(mode=0o700);b.m.run(['mount','--bind',str(empty),path])
        import pwd
        user=pwd.getpwnam('mysql');os.chmod(s['logical'],0o755);os.chown(os.path.dirname(s['socket']),user.pw_uid,user.pw_gid);os.chmod(os.path.dirname(s['socket']),0o700)
        data=s['logical']+'/sulb-shipment-'+s['token'];pathlib.Path(data).mkdir(mode=0o700);os.chown(data,user.pw_uid,user.pw_gid)
        if os.path.lexists(s['socket']) or os.path.lexists(s['socket']+'.lock'):raise CheckBlocked('HISTORY_PRIVATE_SOCKET_NOT_EMPTY')
        drop=['setpriv','--reuid='+str(user.pw_uid),'--regid='+str(user.pw_gid),'--clear-groups','--bounding-set=-all','--inh-caps=-all','--ambient-caps=-all','--no-new-privs','--']
        args=b.mysql_args(data,s['socket']);result['stage']='INITIALIZE_OWNED_SERVER'
        b.private_command(drop+args+['--initialize-insecure'],work/'initialize-private.log',900,b.clean_env(data))
        result['stage']='START_OWNED_SERVER'
        with open(work/'server-private.log','ab') as log:server=subprocess.Popen(drop+args,stdin=subprocess.DEVNULL,stdout=log,stderr=log,env=b.clean_env(data))
        ready=False
        for _ in range(120):
            if server.poll() is not None:raise CheckBlocked('HISTORY_OWNED_SERVER_EXITED')
            if pathlib.Path(s['socket']).exists():
                if pathlib.Path('/proc/'+str(server.pid)+'/attr/current').read_text().strip()!=s['label']:raise CheckBlocked('HISTORY_APPARMOR_LABEL_CHANGED')
                probe=['php','-d','display_errors=0','-d','log_errors=0','-r','try{new PDO("mysql:unix_socket=".$argv[1],"root","");}catch(Throwable $e){exit(1);}',s['socket']]
                if subprocess.run(probe,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env=b.clean_env(work),timeout=5).returncode==0:ready=True;break
            time.sleep(1)
        if not ready:raise CheckBlocked('HISTORY_SERVER_READINESS_TIMEOUT')
        ctx={'mode':'NATIVE_HISTORY_RESTORE','production_connected':False,'datadir':data,'socket':s['socket'],'socket_identity':b.identity(s['socket']),'server_pid':server.pid,'host_net':s['host_net'],'host_pid':s['host_pid'],'private_net':os.readlink('/proc/self/ns/net'),'private_pid':os.readlink('/proc/self/ns/pid'),'masking_complete':True,'limits_enforced':True}
        (work/'native-context.json').write_text(json.dumps(ctx));(work/'native-context.json').chmod(0o600)
        result['stage']='AUTHENTICATED_PRIVATE_RESTORE'
        cmd=['php','-d','memory_limit=128M','-d','display_errors=0','-d','log_errors=0',str(work/'source/ops/sulb-rc/run-private-history-restore.php'),s['socket'],data,str(work/'snapshot.enc'),str(work/'snapshot.key'),str(work/'manifest-private.json'),str(work/'native-context.json'),str(work/'history-result.json')]
        try:b.private_command(cmd,work/'restore-private.log',3600,b.clean_env(work))
        except CheckBlocked:
            if not (work/'history-result.json').is_file():raise
        result.update(json.loads((work/'history-result.json').read_text()));result['stage']='COMPLETE' if result['snapshot_restore_rehearsal']=='PASS' else 'RESTORE_VALIDATION'
    except Exception as e:
        result.update(snapshot_restore_rehearsal='BLOCKED',reason=e.code if isinstance(e,CheckBlocked) else 'HISTORY_NATIVE_RESTORE_FAILED',failure=exception_evidence(result['stage'],e))
    finally:
        if server is not None:
            if server.poll() is None:
                server.terminate()
                try:server.wait(timeout=60)
                except subprocess.TimeoutExpired:server.kill();server.wait(timeout=10);result.update(snapshot_restore_rehearsal='BLOCKED',reason='HISTORY_SERVER_FORCED_STOP')
            result['owned_server_stopped']=server.poll() is not None
        (work/'child-summary.json').write_text(json.dumps(result,indent=2));(work/'child-summary.json').chmod(0o600)
    return 0 if result['snapshot_restore_rehearsal']=='PASS' else 78

def main(argv):
    result={'history_status':'BLOCKED','production_rows_extraction_authorized':False,'release_decision':'NO-GO'};group=None;work=None;before=None;identities={}
    try:
        mode=argv[0] if argv else ''
        if mode not in ['--check-readiness','--capture-approved','--restore-approved']:raise CheckBlocked('HISTORY_EXPLICIT_MODE_REQUIRED')
        if mode=='--capture-approved':
            policy=json.loads(pathlib.Path(__file__).with_name('release.json').read_text())
            if policy.get('history_capture',{}).get('production_extraction_authorized') is not True:raise CheckBlocked('HISTORY_EXTRACTION_NOT_AUTHORIZED')
        if sys.platform!='linux' or os.geteuid()!=0:raise CheckBlocked('HISTORY_LINUX_ROOT_REQUIRED')
        os.umask(0o077);disable_core_dumps();result['core_dumps_disabled']=True
        if mode=='--check-readiness':
            if len(argv)!=1:raise CheckBlocked('HISTORY_ARGUMENTS_INVALID')
            work=BASE/('sulb-history-ready-'+uuid.uuid4().hex[:16]);work.mkdir(mode=0o700)
        else:
            if len(argv)!=2:raise CheckBlocked('HISTORY_ARGUMENTS_INVALID')
            original=secure_directory(argv[1],'sulb-history-ready-')
            work=original if mode=='--capture-approved' else BASE/('sulb-history-restore-'+uuid.uuid4().hex[:16])
            if mode=='--restore-approved':work.mkdir(mode=0o700)
        group=OwnedGroup(work);group.create()
        if mode in ['--check-readiness','--capture-approved']:
            payload=collector(mode,work,group);result.update(payload)
            expected='history_readiness' if mode=='--check-readiness' else 'history_capture'
            result['history_status']='PASS' if payload.get(expected)=='PASS' and payload.get('history_status')!='BLOCKED' else 'BLOCKED'
            result['production_rows_extraction_authorized']=mode=='--capture-approved'
        else:
            key=BASE/'sulb-history-keys'/(original.name[len('sulb-history-ready-'):]+'.key')
            files={'snapshot.enc':original/'snapshot.enc','manifest-private.json':original/'manifest-private.json','snapshot.key':key}
            for name,path in files.items():
                if path.is_symlink() or not path.is_file() or path.stat().st_uid!=0 or path.stat().st_mode&0o077:raise CheckBlocked('HISTORY_PRIVATE_BUNDLE_FILES_REQUIRED')
                shutil.copyfile(path,work/name);(work/name).chmod(0o600)
            b.checked_capture(b.CAPTURE) # Pin existing proven baseline, never re-extract Schema.
            before=b.m.process_state();data,sockets,_=b.m.production_paths(before[0]);identities={p:b.identity(p) for p in [data,'/var/lib','/run']+sockets}
            if data!='/home/mysql':raise CheckBlocked('HISTORY_PROVEN_MAPPING_CHANGED')
            token=uuid.uuid4().hex;plan=exact_socket_plan(before[1],data,sockets,token)
            source=b.archive_source(pathlib.Path(__file__).resolve().parents[2],work,group)
            launch={'data':data,'sockets':sockets,'logical':plan['selected_logical_datadir'],'socket':plan['selected_socket_path'],'token':token,'label':before[1],'host_net':os.readlink('/proc/self/ns/net'),'host_pid':os.readlink('/proc/self/ns/pid'),'cgroup_name':group.path.name}
            (work/'launch.json').write_text(json.dumps(launch));(work/'launch.json').chmod(0o600)
            try:b.private_command(group.wrap(['unshare','--mount','--net','--pid','--fork',sys.executable,str(source/'ops/sulb-rc/history-stage.py'),'--restore-child',str(work)]),work/'namespace-private.log',4800,b.clean_env(work))
            except CheckBlocked:
                if not (work/'child-summary.json').is_file():raise
            result.update(json.loads((work/'child-summary.json').read_text()))
            result['history_status']=result['snapshot_restore_rehearsal']
    except Exception as e:result.update(history_status='BLOCKED',reason=e.code if isinstance(e,CheckBlocked) else 'HISTORY_STAGE_FAILED',failure=exception_evidence('HISTORY_STAGE',e))
    finally:
        if group is not None:
            try:
                if group.created:result['memory_events']=(group.path/'memory.events').read_text().strip().splitlines()
                group.cleanup();result['owned_cgroup_removed']=True
            except Exception:result.update(history_status='BLOCKED',reason='HISTORY_OWNED_RESOURCE_CLEANUP_FAILED')
        if before is not None:
            try:
                if b.m.process_state()!=before or any(b.identity(p)!=v for p,v in identities.items()):raise CheckBlocked('HISTORY_HOST_IDENTITY_CHANGED')
                result['host_production_identities_unchanged']=True
            except Exception:result.update(history_status='BLOCKED',reason='HISTORY_HOST_IDENTITY_NOT_CONFIRMED')
        if work is not None:
            result['private_work_directory']=str(work);(work/'safe-summary.json').write_text(json.dumps(result,indent=2));(work/'safe-summary.json').chmod(0o600)
    print(json.dumps(result,indent=2));return 0 if result.get('history_status')=='PASS' else 78

if __name__=='__main__':
    if len(sys.argv)==3 and sys.argv[1]=='--restore-child':sys.exit(restore_child(sys.argv[2]))
    sys.exit(main(sys.argv[1:]))
