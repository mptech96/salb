"""Exact allowed socket in private mount only. No mysqld or cgroup probe."""
import contextlib, importlib.util, io, json, os, pathlib, socket, stat, sys, tempfile, uuid
from native_isolation_checks import exact_socket_plan, CheckBlocked
spec=importlib.util.spec_from_file_location('isolation',pathlib.Path(__file__).with_name('close-native-isolation.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def identity(path):
    s=os.stat(path);return [s.st_dev,s.st_ino]
def child(work,data,sockets,logical,sock):
    # Existing reviewed masking runs first, before ANY same-name socket creation.
    with contextlib.redirect_stdout(io.StringIO()):
        m.namespace_child(work,data,sockets,logical,os.path.dirname(sock))
    m.require(not os.path.lexists(sock) and not os.path.lexists(sock+'.lock'),'SOCKET_TARGET_NOT_EMPTY')
    probe=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM)
    try:
        probe.bind(sock)
        with open(sock+'.lock','x') as lock:lock.write('OWNED_NAMESPACE_PROBE')
        m.require(stat.S_ISSOCK(os.stat(sock).st_mode),'PRIVATE_SOCKET_BIND_FAILED')
        print(json.dumps({'private_socket_bind':True,'private_lock_create':True,'production_socket_hidden_before_bind':True,'socket_path':sock}))
    finally:probe.close()
def main():
    result={'decision':'BLOCKED','release_decision':'NO-GO','mysqld_started':False,'database_connections':0,'existing_cgroups_modified':False,
            'cgroup':'PRIOR_OPERATOR_PASS_REUSED_d3ab1a13','scope':'exact_socket_mapping_only'}
    try:
        m.require(sys.platform=='linux' and os.geteuid()==0,'LINUX_ROOT_REQUIRED')
        before=m.process_state();data,sockets,evidence=m.production_paths(before[0])
        m.require(data=='/home/mysql','PRIOR_MAPPING_CHANGED')
        identities={p:identity(p) for p in [data,'/var/lib','/run']+sockets}
        plan=exact_socket_plan(before[1],data,sockets,uuid.uuid4().hex);result['apparmor']=plan
        with tempfile.TemporaryDirectory(prefix='sulb-socket-proof-',dir='/home/mgye-api-sulb') as work:
            os.chmod(work,0o700)
            result['namespace']=json.loads(m.run(['unshare','--mount','--net','--pid','--fork',sys.executable,str(pathlib.Path(__file__).resolve()),'--child',work,data,json.dumps(sockets),plan['selected_logical_datadir'],plan['selected_socket_path']]))
        m.require(all(identity(p)==v for p,v in identities.items()),'HOST_PATH_IDENTITY_CHANGED')
        m.require(m.process_state()==before,'PRODUCTION_PROCESS_CHANGED')
        result.update(decision='NATIVE_STAGING_GO',host_socket_inodes_unchanged=True)
    except (CheckBlocked,m.Blocked) as e:
        result['blocker']=str(e);result['evidence']=e.evidence
    except Exception as e:
        result['blocker']='PRIVATE_SOCKET_PROOF_FAILED';result['exception_type']=type(e).__name__
    print(json.dumps(result,indent=2));return 0 if result['decision']=='NATIVE_STAGING_GO' else 78
if __name__=='__main__':
    if len(sys.argv)>1 and sys.argv[1]=='--child':child(sys.argv[2],sys.argv[3],json.loads(sys.argv[4]),sys.argv[5],sys.argv[6])
    else:sys.exit(main())
