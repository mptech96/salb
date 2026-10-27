"""Targeted loaded-policy/cgroup checks. No DB, profile load or parent cgroup writes."""
import errno, json, os, pathlib, re, shutil, subprocess, time, uuid

class CheckBlocked(Exception):
    def __init__(self,code,evidence=None): super().__init__(code); self.code=code; self.evidence=evidence or {}
PERMS={'read':4,'write':2,'create':16,'open':64,'lock':0x8000}
def parse_access_reply(reply):
    fields={}
    for line in reply.decode('ascii').splitlines():
        match=re.fullmatch(r'(allow|deny|audit|quiet) 0x([0-9a-fA-F]{8})',line)
        if not match or match[1] in fields: raise CheckBlocked('APPARMOR_QUERY_REPLY_INVALID')
        fields[match[1]]=int(match[2],16)
    if set(fields)!={'allow','deny','audit','quiet'}: raise CheckBlocked('APPARMOR_QUERY_REPLY_INCOMPLETE')
    return fields
def file_access_payload(label,path):
    if '\0' in label or '\0' in path: raise CheckBlocked('APPARMOR_QUERY_INPUT_INVALID')
    return b'label\0'+label.encode()+b'\0\x02'+path.encode()
def access_query(label,path):
    # Same documented binary .access protocol used by libapparmor; full path,
    # class FILE=2. No profile-loading interface is ever opened.
    payload=file_access_payload(label,path)
    fd=os.open('/sys/kernel/security/apparmor/.access',os.O_RDWR|os.O_CLOEXEC)
    try:
        if os.write(fd,payload)!=len(payload): raise CheckBlocked('APPARMOR_QUERY_WRITE_INCOMPLETE')
        return parse_access_reply(os.read(fd,1024))
    finally: os.close(fd)
def policy_row(label,path,names,query=access_query):
    masks=query(label,path); mask=sum(PERMS[n] for n in names)
    denied=[n for n in names if masks['deny']&PERMS[n]]
    missing=[n for n in names if not masks['allow']&PERMS[n]]
    return {'path':path,'required_operations':names,'allow_mask':hex(masks['allow']),'deny_mask':hex(masks['deny']),
            'explicit_denied_operations':denied,'missing_explicit_allow':missing,
            'strictly_allowed':not(mask&~(masks['allow']&~masks['deny']))}
def policy_check(label,data,sockets,token,query=access_query):
    name=re.sub(r' \((enforce|complain|kill)\)$','',label)
    if name=='unconfined': raise CheckBlocked('UNCONFINED_PROFILE_REQUIRES_REVIEW')
    evidence={'profile':label,'source':'loaded_kernel_policy','source_line':'Kernel query exposes permission decisions, not source-file line numbers.',
              'complain_missing_allow_is_not_explicit_deny':True,'plans':[]}
    # Alternate is a CANONICAL directory created only inside a private /var/lib
    # mount, never an unconfined binary, symlink bypass or policy exception.
    original_parents=sorted({os.path.dirname(s) for s in sockets})
    plans=[(data,tuple(original_parents)),(data,('/run/mysqld',)),('/var/lib/mysql',('/run/mysqld',))]
    for logical,parents in dict.fromkeys(plans):
        paths=[(logical+'/sulb-shipment-'+token+'/ibdata1',['read','write','create','open','lock'])]
        paths.extend((p+'/sulb-stage-'+token+'.sock',['read','write','create','open']) for p in parents)
        rows=[policy_row(name,p,ops,query) for p,ops in paths]
        plan={'logical_datadir':logical,'requires_new_private_mount_proof':logical!=data,'queries':rows,'strictly_allowed':all(r['strictly_allowed'] for r in rows)}
        evidence['plans'].append(plan)
        if plan['strictly_allowed']:
            evidence['selected_logical_datadir']=logical;evidence['selected_socket_parent']=parents[0];return evidence
    raise CheckBlocked('APPARMOR_NO_PROVEN_ALLOWED_PLAN',evidence)
def exception_evidence(phase,exc):
    out={'phase':phase,'exception_type':type(exc).__name__}
    if isinstance(exc,OSError):
        out.update(errno=exc.errno,errno_name=errno.errorcode.get(exc.errno,'UNKNOWN'))
        out['classification']='HOST_PERMISSION_OR_READONLY' if exc.errno in [errno.EACCES,errno.EPERM,errno.EROFS] else ('HOST_INTERFACE_OR_DEVICE_UNAVAILABLE' if exc.errno in [errno.ENOENT,errno.ENODEV,errno.ENOSYS,errno.EOPNOTSUPP] else 'REQUEST_OR_RUNTIME_FAILURE_NOT_PROOF_OF_UNSUPPORTED_HOST')
    if isinstance(exc,subprocess.CalledProcessError):
        out['returncode']=exc.returncode
        text=(exc.stderr or b'').decode(errors='replace') if isinstance(exc.stderr,bytes) else str(exc.stderr or '')
        out['stderr_kind']=next((s for s in ['Permission denied','Read-only file system','No such device','Invalid argument','Operation not permitted','No such file or directory'] if s in text),'NOT_EXPORTED')
    return out
def io_target(fs_device,partition_parent=None):
    target=partition_parent or fs_device
    if not re.fullmatch(r'[1-9]\d*:\d+',target): raise CheckBlocked('IO_DEVICE_MAPPING_UNPROVEN')
    return target
def cgroup_check(work):
    root=pathlib.Path('/sys/fs/cgroup'); cg=None; created=False; evidence={'phase':'read_delegation','controls':{},'existing_cgroups_modified':False}; error=None
    phase='read_delegation'
    try:
        enabled=set((root/'cgroup.subtree_control').read_text().split());missing=sorted({'memory','cpu','pids','io'}-enabled)
        if missing: raise CheckBlocked('CGROUP_PARENT_CONTROLLERS_NOT_ENABLED',{'phase':phase,'missing_controllers':missing})
        phase='resolve_io_device'
        fsdev=subprocess.check_output(['findmnt','-n','-o','MAJ:MIN','-T',str(work)],stderr=subprocess.PIPE,timeout=5).decode().strip()
        sysdev=pathlib.Path('/sys/dev/block',fsdev).resolve();parent=None
        if (sysdev/'partition').exists(): parent=(sysdev.parent/'dev').read_text().strip()
        dev=io_target(fsdev,parent)
        if not pathlib.Path('/sys/dev/block',dev).exists(): raise CheckBlocked('IO_DEVICE_NOT_PRESENT')
        evidence.update(filesystem_device=fsdev,io_charge_device=dev)
        phase='create_owned_cgroup';cg=root/('sulb-isolation-'+uuid.uuid4().hex);cg.mkdir();created=True
        limits={'memory.max':'268435456','cpu.max':'25000 100000','pids.max':'32','io.max':dev+' rbps=1048576 wbps=1048576'}
        if (cg/'memory.swap.max').exists(): limits['memory.swap.max']='0'
        elif re.search(r'^SwapTotal:\s+0\s+kB$',pathlib.Path('/proc/meminfo').read_text(),re.M): evidence['swap']='NO_HOST_SWAP'
        else: raise CheckBlocked('CGROUP_SWAP_CONTROL_UNAVAILABLE')
        for key,value in limits.items():
            phase='write_'+key;(cg/key).write_text(value);phase='readback_'+key
            read=' '.join((cg/key).read_text().split())
            valid=read==value if key!='io.max' else dev in read and 'wbps=1048576' in read and 'rbps=1048576' in read
            if not valid: raise CheckBlocked('CGROUP_CONTROL_READBACK_MISMATCH',{'phase':phase,'control':key})
            evidence['controls'][key]='PASS'
        # Only NEW child shell enters this new group; no preexec_fn hiding errno.
        shell=['/bin/bash','-c','group=$1; shift; printf "%s" "$$" >"$group/cgroup.procs" || exit 79; exec "$@"','sulb-owned-child',str(cg)]
        phase='attach_owned_child';p=subprocess.Popen(shell+['/bin/sleep','2'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,env={**os.environ,'LC_ALL':'C'})
        attached=False
        for _ in range(100):
            attached=str(p.pid) in (cg/'cgroup.procs').read_text().split()
            if attached or p.poll() is not None: break
            time.sleep(.01)
        stdout,stderr=p.communicate(timeout=3)
        if p.returncode or not attached:
            details=exception_evidence(phase,subprocess.CalledProcessError(p.returncode,p.args,stdout,stderr))
            raise CheckBlocked('CGROUP_CHILD_ATTACHMENT_FAILED',details)
        evidence['child_attached']=True
        phase='direct_io_probe';start=time.monotonic()
        subprocess.run(shell+[shutil.which('dd') or '/bin/dd','if=/dev/zero','of='+str(work/'owned-io-probe'),'bs=1048576','count=4','oflag=direct','conv=fsync','status=none'],stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=20,check=True,env={**os.environ,'LC_ALL':'C'})
        elapsed=time.monotonic()-start;phase='verify_io_accounting';stat=(cg/'io.stat').read_text()
        charged=any(x.startswith(dev+' ') and int(re.search(r'wbytes=(\d+)',x)[1])>=4194304 for x in stat.splitlines() if 'wbytes=' in x)
        if not charged: raise CheckBlocked('CGROUP_IO_ACCOUNTING_UNPROVEN',{'phase':phase,'observed_device_ids':[x.split()[0] for x in stat.splitlines()]})
        phase='verify_io_throttle'
        if elapsed<2: raise CheckBlocked('CGROUP_IO_THROTTLE_UNPROVEN',{'phase':phase,'elapsed_seconds':round(elapsed,3)})
        evidence.update(phase='complete',io_throttle_observed=True,elapsed_seconds=round(elapsed,3))
    except Exception as exc:
        detail=exception_evidence(phase,exc);detail.update(exc.evidence if isinstance(exc,CheckBlocked) else {})
        evidence['failure']=detail;error=exc.code if isinstance(exc,CheckBlocked) else 'CGROUP_OPERATION_FAILED'
    finally:
        if created and cg is not None and cg.exists():
            try:
                if (cg/'cgroup.procs').read_text().strip(): raise CheckBlocked('OWNED_CGROUP_STILL_POPULATED')
                cg.rmdir();evidence['owned_cgroup_removed']=True
            except Exception as exc:
                evidence['cleanup_failure']=exception_evidence('cleanup_owned_cgroup',exc);error=error or 'OWNED_CGROUP_CLEANUP_FAILED'
    if error: raise CheckBlocked(error,evidence)
    return evidence
