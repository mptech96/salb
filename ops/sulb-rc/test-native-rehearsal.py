"""Native runner contracts only. No DB, namespace, cgroup or server started."""
import contextlib, importlib.util, io, json, os, pathlib, tarfile, tempfile, unittest
from unittest import mock
from types import SimpleNamespace
import native_rehearsal_resources as resources
spec=importlib.util.spec_from_file_location('native_runner',pathlib.Path(__file__).with_name('rehearse-native-schema.py'))
n=importlib.util.module_from_spec(spec);spec.loader.exec_module(n)
FIXTURES=pathlib.Path(__file__).resolve().parents[2]/'backend/storage/framework/testing/native-runner-contracts'
FIXTURES.mkdir(parents=True,exist_ok=True)
class Contracts(unittest.TestCase):
    def test_missing_execute_flag_cannot_reach_host(self):
        with mock.patch.object(n.m,'process_state',side_effect=AssertionError('must not touch host')) as state,contextlib.redirect_stdout(io.StringIO()):
            self.assertEqual(n.main(False),78)
        state.assert_not_called()
    def test_environment_has_no_database_or_cloudpanel_credentials(self):
        with mock.patch.dict(os.environ,{'DB_PASSWORD':'secret','MYSQL_PWD':'secret','COMPOSER_AUTH':'secret','BASH_ENV':'malicious'}):env=n.clean_env('/owned')
        self.assertFalse(set(env)&{'DB_PASSWORD','MYSQL_PWD','COMPOSER_AUTH','BASH_ENV'});self.assertEqual(env['HOME'],'/owned')
    def test_server_must_have_owned_datadir(self):
        for p in ['/home/mysql','/var/lib/mysql','/home/mysql/sulbprod','/home/mysql/sulb-shipment-'+('a'*31),'sulbprod']:
            with self.assertRaises(n.CheckBlocked):n.mysql_args(p,'/run/mysqld/mysqld.sock')
    def test_server_never_loads_production_config_or_tcp(self):
        a=n.mysql_args('/home/mysql/sulb-shipment-'+'a'*32,'/run/mysqld/mysqld.sock')
        self.assertEqual(a[1],'--no-defaults')
        for flag in ['--skip-networking','--mysqlx=0','--skip-log-bin','--local-infile=OFF','--secure-file-priv=NULL','--restrict-fk-on-non-standard-key=ON']:self.assertIn(flag,a)
        self.assertFalse(any('defaults-file' in x or x=='--skip-grant-tables' for x in a))
    def test_capture_wrong_path_refused(self):
        with self.assertRaises(n.CheckBlocked):n.checked_capture('/tmp/schema-private.json')
    def test_capture_hash_mismatch_refused(self):
        with tempfile.TemporaryDirectory(dir=FIXTURES) as d:
            p=pathlib.Path(d)/'schema-private.json';p.write_bytes(b'{}')
            with mock.patch.object(n,'CAPTURE',str(p)):
                with self.assertRaises(n.CheckBlocked) as e:n.checked_capture(p)
            self.assertEqual(e.exception.code,'PINNED_CAPTURE_HASH_MISMATCH')
    def test_business_row_capture_refused_even_if_hash_matches(self):
        with tempfile.TemporaryDirectory(dir=FIXTURES) as d:
            p=pathlib.Path(d)/'schema-private.json';raw=json.dumps({'mode':'PRIVATE_SCHEMA_ONLY','business_rows_exported':True}).encode();p.write_bytes(raw)
            with mock.patch.object(n,'CAPTURE',str(p)),mock.patch.object(n,'SHA',n.hashlib.sha256(raw).hexdigest()):
                with self.assertRaises(n.CheckBlocked) as e:n.checked_capture(p)
            self.assertEqual(e.exception.code,'SCHEMA_ONLY_CAPTURE_REQUIRED')
    def test_owned_group_not_created_never_cleaned(self):
        with tempfile.TemporaryDirectory(dir=FIXTURES) as d:
            g=resources.OwnedGroup(pathlib.Path(d),pathlib.Path(d));g.cleanup();self.assertFalse(g.path.exists())
    def test_foreign_cgroup_identity_never_killed(self):
        with tempfile.TemporaryDirectory(dir=FIXTURES) as d:
            g=resources.OwnedGroup(pathlib.Path(d),pathlib.Path(d));g.path.mkdir();g.created=True;g.identity=(-1,-1)
            with self.assertRaises(n.CheckBlocked):g.cleanup()
            self.assertFalse((g.path/'cgroup.kill').exists())
    def test_group_cannot_wrap_before_owned_creation(self):
        with self.assertRaises(n.CheckBlocked):resources.OwnedGroup('/notused').wrap(['mysqld'])
    def test_cleanup_only_empty_owned_child(self):
        with tempfile.TemporaryDirectory(dir=FIXTURES) as d:
            g=resources.OwnedGroup(pathlib.Path(d),pathlib.Path(d));g.path.mkdir();g.created=True
            s=g.path.stat();g.identity=(s.st_dev,s.st_ino)
            # Kernel cgroup files are virtual; mock only their content/rmdir behavior.
            procs=mock.Mock();procs.read_text.return_value=''
            real=g.path;fake=mock.Mock();fake.stat.return_value=s;fake.parent=g.root;fake.name=real.name;fake.__truediv__=mock.Mock(return_value=procs)
            g.path=fake;g.cleanup();fake.rmdir.assert_called_once();self.assertFalse(g.created)
    def test_child_direct_host_invocation_refused_before_mount(self):
        with tempfile.TemporaryDirectory(dir=FIXTURES) as d:
            work=pathlib.Path(d);(work/'launch.json').write_text(json.dumps({'data':'/home/mysql','sockets':[],'logical':'/home/mysql','socket':'/run/mysqld/mysqld.sock'}))
            with mock.patch.object(n.os,'getpid',return_value=100),mock.patch.object(n.m,'namespace_child',side_effect=AssertionError('no mounts')) as mount:
                self.assertEqual(n.native_child(work),78)
            mount.assert_not_called();r=json.loads((work/'child-summary.json').read_text());self.assertEqual(r['blocker'],'PRIVATE_PID1_REQUIRED_BEFORE_MOUNTS')
    def test_diagnostics_never_export_ddl_or_secrets(self):
        with tempfile.TemporaryDirectory(dir=FIXTURES) as d:
            p=pathlib.Path(d)/'private.log';p.write_text('SECRET CREATE TABLE private_customer MY-010123 SQLSTATE[HY000] Permission denied')
            r=n.safe_log_codes(p)
            self.assertEqual(r['mysql_error_ids'],['MY-010123']);self.assertEqual(r['sqlstates'],['HY000'])
            self.assertNotIn('SECRET',str(r));self.assertNotIn('private_customer',str(r))
    def resource_fixture(self):
        g=resources.OwnedGroup('/not-used');root=mock.MagicMock();cg=mock.MagicMock();controller=mock.Mock();controller.read_text.return_value='memory cpu pids io'
        root.__truediv__.return_value=controller;g.root=root;g.path=cg
        st=SimpleNamespace(st_dev=1,st_ino=2);cg.stat.return_value=st
        files={}
        def file(key):
            if key not in files:
                f=mock.Mock();f.exists.return_value=True
                f.write_text.side_effect=lambda v,f=f:setattr(f.read_text,'return_value',v)
                files[key]=f
            return files[key]
        cg.__truediv__.side_effect=file
        sysdev=mock.MagicMock();sysdev.resolve.return_value=sysdev;(sysdev/'partition').exists.return_value=False
        return g,controller,files,sysdev
    def test_resource_enforcement_uses_exact_approved_limits_without_parent_writes(self):
        g,parent,files,device=self.resource_fixture()
        with mock.patch.object(resources,'pathlib',SimpleNamespace(Path=lambda *a:device)),mock.patch.object(resources.subprocess,'check_output',return_value=b'8:0\n'):
            g.create()
        self.assertEqual(g.controls,{'memory.max':'268435456','cpu.max':'25000 100000','pids.max':'32','io.max':'8:0 rbps=1048576 wbps=1048576','memory.swap.max':'0'})
        parent.write_text.assert_not_called();cg_args=g.wrap(['owned-command']);self.assertEqual(cg_args[-1],'owned-command')
    def test_failed_limit_readback_stops_before_execution(self):
        g,parent,files,device=self.resource_fixture()
        f=mock.Mock();f.write_text.return_value=None;f.read_text.return_value='max';files['memory.max']=f
        with mock.patch.object(resources,'pathlib',SimpleNamespace(Path=lambda *a:device)),mock.patch.object(resources.subprocess,'check_output',return_value=b'8:0\n'):
            with self.assertRaises(n.CheckBlocked) as e:g.create()
        self.assertEqual(e.exception.code,'OWNED_LIMIT_READBACK_FAILED');self.assertEqual(e.exception.evidence['control'],'memory.max')
        self.assertEqual(g.phase,'enforce_memory.max');parent.write_text.assert_not_called()
    def archive_case(self,name,kind=None):
        with tempfile.TemporaryDirectory(dir=FIXTURES) as d:
            work=pathlib.Path(d)
            with tarfile.open(work/'source.tar','w') as t:
                info=tarfile.TarInfo(name)
                if kind:info.type=kind;info.linkname='/etc/mysql'
                t.addfile(info)
            group=mock.Mock();group.wrap.side_effect=lambda a:a
            with mock.patch.object(n.subprocess,'run'):
                with self.assertRaises(n.CheckBlocked):n.archive_source(pathlib.Path(d),work,group)
    def test_archive_path_traversal_refused(self):self.archive_case('../escape')
    def test_archive_production_environment_refused(self):self.archive_case('backend/.env')
    def test_archive_symlink_refused(self):self.archive_case('backend/config-link',tarfile.SYMTYPE)
    def test_archive_vendor_refused(self):self.archive_case('backend/vendor/file.php')
if __name__=='__main__':unittest.main()
