"""Owned cgroup enforcement for the approved rehearsal (not an I/O benchmark)."""
import os, pathlib, re, subprocess, time, uuid
from native_isolation_checks import CheckBlocked, io_target

class OwnedGroup:
    def __init__(self, work, root=pathlib.Path('/sys/fs/cgroup')):
        self.work=pathlib.Path(work); self.root=root; self.path=root/('sulb-schema-'+uuid.uuid4().hex)
        self.created=False; self.identity=None; self.phase='not_created'; self.controls={}
    def create(self):
        self.phase='read_delegation'
        if not {'memory','cpu','pids','io'} <= set((self.root/'cgroup.subtree_control').read_text().split()):
            raise CheckBlocked('PREVIOUSLY_APPROVED_CONTROLLERS_CHANGED')
        self.phase='resolve_io_device'
        dev=subprocess.check_output(['findmnt','-n','-o','MAJ:MIN','-T',str(self.work)],stderr=subprocess.PIPE,timeout=5).decode().strip()
        sysdev=pathlib.Path('/sys/dev/block',dev).resolve()
        dev=io_target(dev,(sysdev.parent/'dev').read_text().strip() if (sysdev/'partition').exists() else None)
        self.phase='create_owned_cgroup'; self.path.mkdir(); self.created=True
        st=self.path.stat(); self.identity=(st.st_dev,st.st_ino)
        limits={'memory.max':'268435456','cpu.max':'25000 100000','pids.max':'32','io.max':dev+' rbps=1048576 wbps=1048576'}
        if (self.path/'memory.swap.max').exists():limits['memory.swap.max']='0'
        elif not re.search(r'^SwapTotal:\s+0\s+kB$',pathlib.Path('/proc/meminfo').read_text(),re.M):
            raise CheckBlocked('SWAP_LIMIT_REQUIRED')
        for key,value in limits.items():
            self.phase='enforce_'+key;(self.path/key).write_text(value)
            read=' '.join((self.path/key).read_text().split())
            ok=read==value if key!='io.max' else dev in read and 'wbps=1048576' in read and 'rbps=1048576' in read
            if not ok:raise CheckBlocked('OWNED_LIMIT_READBACK_FAILED',{'control':key})
            self.controls[key]=value
        self.phase='enforced';return self
    def wrap(self,args):
        if not self.created:raise CheckBlocked('OWNED_CGROUP_NOT_CREATED')
        return ['/bin/bash','-c','g=$1; shift; printf "%s" "$$" >"$g/cgroup.procs" || exit 79; exec "$@"','sulb-schema-owned',str(self.path)]+args
    def cleanup(self):
        if not self.created:return
        st=self.path.stat()
        if (st.st_dev,st.st_ino)!=self.identity or self.path.parent!=self.root or not re.fullmatch('sulb-schema-[a-f0-9]{32}',self.path.name):
            raise CheckBlocked('OWNED_CGROUP_IDENTITY_CHANGED')
        if (self.path/'cgroup.procs').read_text().strip():
            # Only this newly created group ever receives our new descendants.
            (self.path/'cgroup.kill').write_text('1')
            for _ in range(100):
                if not (self.path/'cgroup.procs').read_text().strip():break
                time.sleep(.05)
        if (self.path/'cgroup.procs').read_text().strip():raise CheckBlocked('OWNED_CGROUP_CLEANUP_FAILED')
        self.path.rmdir();self.created=False
