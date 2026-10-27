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
    name=re.sub(r' \((enforce|complain|kill)\)$','',label)
    require(name!='unconfined','UNCONFINED_PROFILE_REQUIRES_REVIEW')
    lib=ctypes.CDLL('libapparmor.so.1',use_errno=True)
    query=lib.aa_query_file_path_len
    query.argtypes=[ctypes.c_uint32,ctypes.c_char_p,ctypes.c_size_t,ctypes.c_char_p,ctypes.c_size_t,ctypes.POINTER(ctypes.c_int),ctypes.POINTER(ctypes.c_int)]
    query.restype=ctypes.c_int
    # Query the LOADED label: no inference from disk source/variables/deny regex.
    paths=[(data+'/sulb-shipment-'+token+'/ibdata1',2|4|16|64|0x8000)]
    for parent in sorted({os.path.dirname(s) for s in sockets}): paths.append((parent+'/sulb-stage-'+token+'.sock',2|4|16|64))
    answers=[]
    for path,mask in paths:
        a=ctypes.c_int(); audit=ctypes.c_int(); n=name.encode(); p=path.encode()
        require(query(mask,n,len(n),p,len(p),ctypes.byref(a),ctypes.byref(audit))==0,'LOADED_POLICY_QUERY_UNAVAILABLE')
        answers.append(bool(a.value))
    require(all(answers),'LOADED_POLICY_DENIES_REQUIRED_PATH')
    return {'assessment':'LOADED_KERNEL_QUERY_PASS','target_queries':len(answers),'source_file_equality_required_for_decision':False,
            'disk_source_equals_loaded':'NOT_CLAIMED','profile_mode':label.rsplit(' ',1)[-1]}
def namespace_child(work,data,sockets):
    work=pathlib.Path(work)
    run(['mount','--make-rprivate','/'])
    # Mask actual production data and all filesystem socket parents in CHILD ONLY.
    targets=private_mount_targets(data,sockets)
    for i,target in enumerate(targets):
        own=work/f'mask-{i}'; own.mkdir(mode=0o700)
        run(['mount','--bind',str(own),target])
        require(os.stat(target).st_ino==own.stat().st_ino and os.stat(target).st_dev==own.stat().st_dev,'MASK_IDENTITY_FAILED')
    run(['mount','-t','proc','proc','/proc'])
    require(os.getpid()==1,'PRIVATE_PID_NAMESPACE_FAILED')
    require(not any(pathlib.Path(data).iterdir()),'PRODUCTION_DATA_VISIBLE_IN_CHILD')
    require(all(not pathlib.Path(s).exists() for s in sockets),'PRODUCTION_SOCKET_VISIBLE_IN_CHILD')
    # A marker can be observed ONLY via the private backing folder; not host paths.
    pathlib.Path(data,'sulb-namespace-marker').write_text('OWNED_PROBE_ONLY')
    routes=pathlib.Path('/proc/net/route').read_text().splitlines()[1:]
    require(not any(x.strip() for x in routes),'NETWORK_ROUTES_PRESENT')
    print(json.dumps({'private_mounts':True,'private_pid':True,'private_network':True,'production_data_socket_hidden':True}))
def cgroup_probe(work):
    root=pathlib.Path('/sys/fs/cgroup')
    # Never enable controllers/change limits in any existing/production cgroup.
    enabled=set((root/'cgroup.subtree_control').read_text().split())
    require({'memory','cpu','pids','io'}<=enabled,'ROOT_CONTROLLERS_NOT_ALREADY_DELEGATED')
    dev=run(['findmnt','-n','-o','MAJ:MIN','-T',str(work)])
    require(re.fullmatch(r'[1-9]\d*:\d+',dev) and pathlib.Path('/sys/dev/block',dev).exists(),'IO_DEVICE_MAPPING_UNPROVEN')
    cg=root/('sulb-isolation-'+uuid.uuid4().hex); cg.mkdir()
    try:
        limits={'memory.max':'268435456','memory.swap.max':'0','cpu.max':'25000 100000','pids.max':'32','io.max':dev+' rbps=1048576 wbps=1048576'}
        for key,value in limits.items():
            (cg/key).write_text(value)
            observed=(cg/key).read_text().strip()
            require(observed==value if key!='io.max' else dev in observed and 'wbps=1048576' in observed and 'rbps=1048576' in observed,'CGROUP_LIMIT_READBACK_FAILED')
        def enter(): (cg/'cgroup.procs').write_text(str(os.getpid()))
        p=subprocess.Popen(['/bin/sleep','1'],preexec_fn=enter,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try: require(str(p.pid) in (cg/'cgroup.procs').read_text().split(),'CGROUP_CHILD_ATTACHMENT_FAILED')
        finally: p.wait(timeout=3)
        start=time.monotonic()
        run(['dd','if=/dev/zero','of='+str(work/'owned-io-probe'),'bs=1048576','count=4','oflag=direct','conv=fsync','status=none'],preexec_fn=enter,timeout=20)
        elapsed=time.monotonic()-start
        stat=(cg/'io.stat').read_text()
        require(any(x.startswith(dev+' ') and int(re.search(r'wbytes=(\d+)',x)[1])>=4194304 for x in stat.splitlines() if 'wbytes=' in x),'IO_ACCOUNTING_NOT_PROVEN')
        require(elapsed>=2,'IO_THROTTLE_NOT_OBSERVED')
        return {'memory_cpu_pids_limits_attached':True,'io_direct_write_throttled':True,'existing_cgroups_modified':False}
    finally:
        # Only this newly-created EMPTY cgroup may be removed; never kill/move host PIDs.
        if not (cg/'cgroup.procs').read_text().strip(): cg.rmdir()
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
                out=run(['unshare','--mount','--net','--pid','--fork',sys.executable,str(pathlib.Path(__file__).resolve()),'--namespace-child',str(work),data,json.dumps(sockets)],timeout=20)
                result['namespace']=json.loads(out)
                require(data_identity==(os.stat(data).st_dev,os.stat(data).st_ino),'HOST_MOUNT_CHANGED')
                require(not pathlib.Path(data,'sulb-namespace-marker').exists(),'HOST_MARKER_PROPAGATED')
                require(all(pathlib.Path(s).exists() for s in sockets),'HOST_SOCKET_DISAPPEARED')
            except Exception as e: failures.append(str(e) if isinstance(e,Blocked) else 'NAMESPACE_PROOF_FAILED')
            try: result['cgroup']=cgroup_probe(work)
            except Exception as e: failures.append(str(e) if isinstance(e,Blocked) else 'CGROUP_PROOF_FAILED')
            require(process_state()==before,'PRODUCTION_PROCESS_LABEL_CHANGED')
            result['blockers']=failures
            if not failures: result['decision']='NATIVE_STAGING_GO'
    except Exception as e:
        result['blockers']=[str(e) if isinstance(e,Blocked) else 'ISOLATION_PROOF_INCOMPLETE']
        if isinstance(e,Blocked) and e.evidence: result['mapping_evidence']=e.evidence
    print(json.dumps(result,indent=2));return 0 if result['decision']=='NATIVE_STAGING_GO' else 78
if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--namespace-child': namespace_child(sys.argv[2],sys.argv[3],json.loads(sys.argv[4]))
    else: sys.exit(main())
