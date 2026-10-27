"""No mysqld/DB access. Only owned temporary mounts, cgroup and 4MiB I/O probe."""
import ctypes, json, os, pathlib, re, shutil, subprocess, sys, tempfile, time, uuid

class Blocked(Exception):
    def __init__(self, code, evidence=None):
        super().__init__(code); self.evidence=evidence
def require(ok, code):
    if not ok: raise Blocked(code)
def run(args, **kw):
    return subprocess.run(args, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          timeout=kw.pop('timeout', 20), check=True, **kw).stdout.decode().strip()
def process_state():
    pid=int(run(['systemctl','show','mysql.service','--property=MainPID','--value']))
    require(pid>1,'MYSQL_SERVICE_PID_UNAVAILABLE')
    label=pathlib.Path(f'/proc/{pid}/attr/current').read_text().strip()
    return pid,label
def verified_datadir(links, cwd, resolve=os.path.realpath):
    roots={resolve(os.path.dirname(x)) for x in links if x.endswith('/ibdata1')}
    require(len(roots)==1,'PRODUCTION_DATADIR_NOT_PROVEN_FROM_OPEN_FILES')
    data=roots.pop(); cwd=resolve(cwd)
    evidence={'resolved_data_root':data,'process_cwd':cwd,
              'open_system_dictionary_same_root':any(x.endswith('/mysql.ibd') and resolve(os.path.dirname(x))==data for x in links)}
    # Location is deployment-specific (e.g. CloudPanel); identity, not a /var/lib
    # prefix, must prove it. Never allow a broad OS/application/working root.
    forbidden={'/','/home','/var','/var/lib','/usr','/etc','/opt','/srv','/run','/tmp','/proc','/sys','/dev',
               '/home/mgye-api-sulb','/home/mgye-sulb','/home/sulb-release'}
    if not data.startswith('/') or data in forbidden or '/htdocs/' in data or data.endswith('/htdocs'):
        raise Blocked('DATADIR_MAPPING_REQUIRES_REVIEW',evidence)
    if not evidence['open_system_dictionary_same_root']:
        raise Blocked('DATADIR_SECOND_ENGINE_ANCHOR_UNAVAILABLE',evidence)
    # cwd is additional observed evidence, not assumed to be a datadir.
    evidence['cwd_matches_data_root']=cwd==data
    return data,evidence
def private_mount_targets(data,sockets):
    targets=[data]+sorted({os.path.dirname(s) for s in sockets})
    targets=sorted(set(t for t in targets if not any(t.startswith(other+'/') for other in targets if other!=t)))
    require(all(t not in ['/','/var','/var/lib','/home','/usr','/etc','/proc','/sys','/dev'] for t in targets),'BROAD_MOUNT_TARGET_REFUSED')
    return targets
def owned_listening_socket_paths(text,inodes):
    paths=[]
    for line in text.splitlines()[1:]:
        parts=line.split(maxsplit=7)
        if len(parts)==8 and parts[6] in inodes and int(parts[3],16)&0x10000:
            require(parts[7].startswith('/'),'ABSTRACT_PRODUCTION_SOCKET_REQUIRES_REVIEW')
            paths.append(parts[7])
    return paths
def production_paths(pid):
    links=[]; inodes=set(); anchors={}
    for fd in pathlib.Path(f'/proc/{pid}/fd').iterdir():
        try: link=os.readlink(fd)
        except OSError: continue
        links.append(link)
        if link.endswith(('/ibdata1','/mysql.ibd')):
            fs=os.stat(fd); named=os.stat(link)
            require((fs.st_dev,fs.st_ino)==(named.st_dev,named.st_ino),'ENGINE_ANCHOR_IDENTITY_CHANGED')
            anchors[link]=True
        m=re.fullmatch(r'socket:\[(\d+)\]',link)
        if m: inodes.add(m[1])
    data,evidence=verified_datadir(list(anchors),os.readlink(f'/proc/{pid}/cwd'))
    sockets=[]
    for raw in owned_listening_socket_paths(pathlib.Path('/proc/net/unix').read_text(),inodes):
        p=os.path.realpath(raw)
        # An actual owned filesystem socket may live alongside a custom datadir.
        import stat
        require(stat.S_ISSOCK(os.stat(p).st_mode),'SOCKET_IDENTITY_NOT_PROVEN')
        sockets.append(p)
    require(bool(sockets),'PRODUCTION_SOCKET_NOT_PROVEN')
    private_mount_targets(data,sockets)
    evidence['owned_filesystem_sockets']=sorted(set(sockets))
    return data,sorted(set(sockets)),evidence
def kernel_policy(label,data,sockets,token):
    from native_isolation_checks import policy_check,CheckBlocked
    try: return policy_check(label,data,sockets,token)
    except CheckBlocked as e: raise Blocked(e.code,e.evidence) from e

def namespace_child(work,data,sockets,logical=None,logical_socket=None):

    work=pathlib.Path(work)
    run(['mount','--make-rprivate','/'])
    # Mask actual production data and all filesystem socket parents in CHILD ONLY.
    targets=private_mount_targets(data,sockets)
    for i,target in enumerate(targets):
        own=work/f'mask-{i}'; own.mkdir(mode=0o700)
        run(['mount','--bind',str(own),target])
        require(os.stat(target).st_ino==own.stat().st_ino and os.stat(target).st_dev==own.stat().st_dev,'MASK_IDENTITY_FAILED')
    if logical is not None and logical!=data:
        require(logical=='/var/lib/mysql','UNREVIEWED_LOGICAL_MAPPING')
        # Do not follow a host /var/lib/mysql symlink to /home/mysql. Construct
        # a real policy-compatible path in a CHILD-only private parent mount.
        run(['mount','-t','tmpfs','-o','size=16m,mode=0755','sulb-private-varlib','/var/lib'])
        pathlib.Path(logical).mkdir(mode=0o700)
        own=work/'policy-data';own.mkdir(mode=0o700)
        run(['mount','--bind',str(own),logical])
        if data.startswith('/var/lib/'):pathlib.Path(data).mkdir(parents=True,exist_ok=True)
        require(os.path.realpath(logical)==logical,'POLICY_CANONICAL_PATH_FAILED')
        require(os.stat(logical).st_ino==own.stat().st_ino and os.stat(logical).st_dev==own.stat().st_dev,'POLICY_DATA_MASK_FAILED')
    if logical_socket and logical_socket not in {os.path.dirname(s) for s in sockets}:
        require(logical_socket=='/run/mysqld','UNREVIEWED_SOCKET_MAPPING')
        run(['mount','-t','tmpfs','-o','size=4m,mode=0755','sulb-private-run','/run'])
        pathlib.Path(logical_socket).mkdir(mode=0o700)
        own=work/'policy-socket';own.mkdir(mode=0o700)
        run(['mount','--bind',str(own),logical_socket])
        require(os.path.realpath(logical_socket)==logical_socket,'POLICY_SOCKET_CANONICAL_PATH_FAILED')
        require(os.stat(logical_socket).st_ino==own.stat().st_ino and os.stat(logical_socket).st_dev==own.stat().st_dev,'POLICY_SOCKET_MASK_FAILED')
    run(['mount','-t','proc','proc','/proc'])
    require(os.getpid()==1,'PRIVATE_PID_NAMESPACE_FAILED')
    require(not any(pathlib.Path(data).iterdir()),'PRODUCTION_DATA_VISIBLE_IN_CHILD')
    require(all(not pathlib.Path(s).exists() for s in sockets),'PRODUCTION_SOCKET_VISIBLE_IN_CHILD')
    # A marker can be observed ONLY via the private backing folder; not host paths.
    pathlib.Path(data,'sulb-namespace-marker').write_text('OWNED_PROBE_ONLY')
    routes=pathlib.Path('/proc/net/route').read_text().splitlines()[1:]
    require(not any(x.strip() for x in routes),'NETWORK_ROUTES_PRESENT')
    print(json.dumps({'private_mounts':True,'private_pid':True,'private_network':True,'production_data_socket_hidden':True,'logical_datadir':logical or data,'logical_socket_parent':logical_socket}))
def cgroup_probe(work):
    from native_isolation_checks import cgroup_check,CheckBlocked
    try: return cgroup_check(work)
    except CheckBlocked as e: raise Blocked(e.code,e.evidence) from e

def main():
    result={'decision':'BLOCKED','mysqld_started':False,'database_connections':0,'production_policy_service_changed':False,'release_decision':'NO-GO'}
    try:
        require(sys.platform=='linux' and os.geteuid()==0,'LINUX_ROOT_REQUIRED')
        before=process_state(); data,sockets,evidence=production_paths(before[0]);result['mapping_evidence']=evidence
        data_identity=(os.stat(data).st_dev,os.stat(data).st_ino)
        with tempfile.TemporaryDirectory(prefix='sulb-isolation-',dir='/home/mgye-api-sulb') as temp:
            work=pathlib.Path(temp); os.chmod(work,0o700)
            failures=[]
            try: result['apparmor']=kernel_policy(before[1],data,sockets,uuid.uuid4().hex)
            except Exception as e: failures.append(str(e) if isinstance(e,Blocked) else 'APPARMOR_QUERY_RUNTIME_UNAVAILABLE')
            try:
                logical=result.get('apparmor',{}).get('selected_logical_datadir',data)
                out=run(['unshare','--mount','--net','--pid','--fork',sys.executable,str(pathlib.Path(__file__).resolve()),'--namespace-child',str(work),data,json.dumps(sockets),logical,result.get('apparmor',{}).get('selected_socket_parent','')],timeout=20)
                result['namespace']=json.loads(out)
                require(data_identity==(os.stat(data).st_dev,os.stat(data).st_ino),'HOST_MOUNT_CHANGED')
                require(not pathlib.Path(data,'sulb-namespace-marker').exists(),'HOST_MARKER_PROPAGATED')
                require(all(pathlib.Path(s).exists() for s in sockets),'HOST_SOCKET_DISAPPEARED')
            except Exception as e: failures.append(str(e) if isinstance(e,Blocked) else 'NAMESPACE_PROOF_FAILED')
            try: result['cgroup']=cgroup_probe(work)
            except Exception as e:
                failures.append(str(e) if isinstance(e,Blocked) else 'CGROUP_PROOF_FAILED')
                if isinstance(e,Blocked):result['cgroup_failure']=e.evidence
            require(process_state()==before,'PRODUCTION_PROCESS_LABEL_CHANGED')
            result['blockers']=failures
            if not failures: result['decision']='NATIVE_STAGING_GO'
    except Exception as e:
        result['blockers']=[str(e) if isinstance(e,Blocked) else 'ISOLATION_PROOF_INCOMPLETE']
        if isinstance(e,Blocked) and e.evidence: result['mapping_evidence']=e.evidence
    print(json.dumps(result,indent=2));return 0 if result['decision']=='NATIVE_STAGING_GO' else 78
def resolve_two_blockers():
    result={'decision':'BLOCKED','scope':'two_verified_blockers_only','mysqld_started':False,'database_connections':0,'production_policy_service_changed':False,'release_decision':'NO-GO'}
    try:
        require(sys.platform=='linux' and os.geteuid()==0,'LINUX_ROOT_REQUIRED')
        receipt=json.loads(pathlib.Path(__file__).with_name('release.json').read_text())['staging_preparation']['native_feasibility']['operator_namespace_receipt']
        before=process_state();data,sockets,evidence=production_paths(before[0]);result['mapping_evidence']=evidence
        require(data==receipt['resolved_data_root'] and all(receipt[k] is True for k in ['private_mounts','private_pid','private_network','production_data_socket_hidden']),'PRIOR_NAMESPACE_RECEIPT_NOT_APPLICABLE')
        result['namespace']={'status':'PRIOR_OPERATOR_PASS_REUSED','source_commit':receipt['source_commit']}; failures=[]
        with tempfile.TemporaryDirectory(prefix='sulb-isolation-',dir='/home/mgye-api-sulb') as temp:
            work=pathlib.Path(temp);os.chmod(work,0o700)
            for key,check in [('apparmor',lambda:kernel_policy(before[1],data,sockets,uuid.uuid4().hex)),('cgroup',lambda:cgroup_probe(work))]:
                try:result[key]=check()
                except Exception as e:
                    failures.append(str(e) if isinstance(e,Blocked) else key.upper()+'_INSPECTION_UNAVAILABLE')
                    if isinstance(e,Blocked): result[key+'_failure']=e.evidence
                    elif isinstance(e,OSError):result[key+'_failure']={'errno':e.errno,'exception_type':type(e).__name__}
            if not failures:
                logical=result['apparmor']['selected_logical_datadir']
                socket_parent=result['apparmor']['selected_socket_parent']
                if logical!=data or socket_parent not in {os.path.dirname(s) for s in sockets}:
                    identities={p:(os.stat(p).st_dev,os.stat(p).st_ino) for p in [data,'/var/lib','/run']}
                    try:
                        result['namespace']=json.loads(run(['unshare','--mount','--net','--pid','--fork',sys.executable,str(pathlib.Path(__file__).resolve()),'--namespace-child',str(work),data,json.dumps(sockets),logical,socket_parent],timeout=20))
                    except subprocess.CalledProcessError as e:
                        text=(e.stderr or b'').decode(errors='replace');match=re.search(r'Blocked: ([A-Z0-9_]+)',text)
                        raise Blocked('POLICY_ALLOWED_ALTERNATIVE_NAMESPACE_FAILED',{'phase':'new_policy_mapping_only','child_code':match[1] if match else 'NAMESPACE_RUNTIME_FAILURE','returncode':e.returncode}) from e
                    require(all(v==(os.stat(p).st_dev,os.stat(p).st_ino) for p,v in identities.items()),'HOST_MAPPING_CHANGED')
                    require(all(pathlib.Path(s).exists() for s in sockets),'HOST_SOCKET_DISAPPEARED')
                result['decision']='NATIVE_STAGING_GO'
            require(process_state()==before,'PRODUCTION_PROCESS_LABEL_CHANGED')
        result['blockers']=failures
    except Exception as e:
        result['decision']='BLOCKED';result['blockers']=[str(e) if isinstance(e,Blocked) else 'TARGETED_ISOLATION_PROOF_INCOMPLETE']
        if isinstance(e,Blocked) and e.evidence:result['failure_evidence']=e.evidence
    print(json.dumps(result,indent=2));return 0 if result['decision']=='NATIVE_STAGING_GO' else 78
if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--namespace-child': namespace_child(sys.argv[2],sys.argv[3],json.loads(sys.argv[4]),sys.argv[5] if len(sys.argv)>5 else None,sys.argv[6] if len(sys.argv)>6 else None)
    elif len(sys.argv)>1 and sys.argv[1]=='--resolve-blockers':sys.exit(resolve_two_blockers())
    else: sys.exit(main())
