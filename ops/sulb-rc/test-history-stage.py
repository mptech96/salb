"""History orchestration contracts only; never creates a real cgroup/server."""
import contextlib, importlib.util, io, json, pathlib, subprocess, sys, tempfile, unittest
from types import SimpleNamespace
from unittest import mock
spec=importlib.util.spec_from_file_location('history',pathlib.Path(__file__).with_name('history-stage.py'))
h=importlib.util.module_from_spec(spec);spec.loader.exec_module(h)
FIX=pathlib.Path(__file__).resolve().parents[2]/'backend/storage/framework/testing/history-stage-contracts';FIX.mkdir(parents=True,exist_ok=True)
class HistorySafety(unittest.TestCase):
    def test_new_backfill_requires_two_decisions_before_any_resources(self):
        for policy in [{},{'legacy_allocation_accounting_approval':{'authorized':True}}]:
            with mock.patch.object(h.pathlib.Path,'read_text',return_value=json.dumps(policy)),mock.patch.object(h,'OwnedGroup') as group,contextlib.redirect_stdout(io.StringIO()) as out:
                self.assertEqual(h.main(['--approved-backfill','unused']),78)
            group.assert_not_called();self.assertEqual(json.loads(out.getvalue())['reason'],'TWO_EXPLICIT_HISTORY_APPROVALS_REQUIRED')
    def test_new_history_pass_cannot_override_failed_server_shutdown(self):
        r={'snapshot_restore_rehearsal':'PASS','historical_status':'PASS','owned_server_stopped':True}
        self.assertEqual(h.restore_outcome(r),'PASS')
        for changed in [{'owned_server_stopped':False},{'snapshot_restore_rehearsal':'BLOCKED'},{'historical_status':'BLOCKED'}]:
            self.assertEqual(h.restore_outcome(r|changed),'BLOCKED')
    def test_unapproved_capture_stops_before_credentials_and_resources(self):
        with mock.patch.object(h.pathlib.Path,'read_text',return_value='{"history_capture":{"production_extraction_authorized":false}}'),mock.patch.object(h,'collector') as collect,mock.patch.object(h,'OwnedGroup') as group,contextlib.redirect_stdout(io.StringIO()) as out:
            self.assertEqual(h.main(['--capture-approved','unused']),78)
        collect.assert_not_called();group.assert_not_called();self.assertEqual(json.loads(out.getvalue())['reason'],'HISTORY_EXTRACTION_NOT_AUTHORIZED')
    def readiness(self,cleanup_error=False,payload=None):
        with tempfile.TemporaryDirectory(dir=FIX) as directory:
            group=mock.Mock(created=False)
            if cleanup_error:group.cleanup.side_effect=RuntimeError('SECRET')
            result=payload or {'history_readiness':'PASS','production_rows_read':False,'snapshot_created':False}
            with mock.patch.object(h,'BASE',pathlib.Path(directory)),mock.patch.object(h.sys,'platform','linux'),mock.patch.object(h.os,'geteuid',return_value=0,create=True),mock.patch.object(h,'OwnedGroup',return_value=group),mock.patch.object(h,'disable_core_dumps'),mock.patch.object(h,'collector',return_value=result),contextlib.redirect_stdout(io.StringIO()) as out:
                code=h.main(['--check-readiness'])
            return code,json.loads(out.getvalue()),group
    def test_readiness_is_success_without_extraction(self):
        code,r,g=self.readiness();self.assertEqual(code,0);self.assertEqual(r['history_status'],'PASS');self.assertFalse(r['production_rows_read']);self.assertFalse(r['snapshot_created']);g.cleanup.assert_called_once()
    def test_cleanup_failure_cannot_be_reported_pass(self):
        code,r,g=self.readiness(True);self.assertEqual(code,78);self.assertEqual(r['history_status'],'BLOCKED');self.assertNotIn('SECRET',json.dumps(r))
    def test_collector_failure_cannot_be_reported_pass(self):
        code,r,g=self.readiness(payload={'history_status':'BLOCKED','reason':'ROLES_NOT_ALLOWED'});self.assertEqual(code,78);self.assertEqual(r['reason'],'ROLES_NOT_ALLOWED')
    def test_restore_direct_host_invocation_never_mounts_or_starts_server(self):
        with tempfile.TemporaryDirectory(dir=FIX) as directory:
            p=pathlib.Path(directory);(p/'launch.json').write_text('{}')
            with mock.patch.object(h.os,'getpid',return_value=40),mock.patch.object(h.b.m,'namespace_child') as mount,mock.patch.object(h.subprocess,'Popen') as proc:
                self.assertEqual(h.restore_child(p),78)
            mount.assert_not_called();proc.assert_not_called()
    def test_restore_public_directory_rejected(self):
        with self.assertRaises(h.CheckBlocked):h.secure_directory('/home/public/bundle','sulb-history-ready-')
    def collector_setup(self):
        return SimpleNamespace(tcgetattr=mock.Mock(return_value='saved'),tcsetattr=mock.Mock(),TCSANOW=0)
    def test_collector_keeps_tty_and_does_not_pass_secrets_to_process(self):
        term=self.collector_setup()
        with tempfile.TemporaryDirectory(dir=FIX) as directory,mock.patch.dict(sys.modules,{'termios':term}),mock.patch.object(h.os,'open',return_value=7),mock.patch.object(h.os,'close') as close,mock.patch.object(h.subprocess,'run',return_value=SimpleNamespace(stdout=b'{"history_readiness":"PASS"}',returncode=0)) as run:
            g=mock.Mock();g.wrap.side_effect=lambda x:x
            h.collector('--check-readiness',pathlib.Path(directory),g)
            args,kw=run.call_args
            self.assertNotIn('start_new_session',kw);self.assertEqual(kw['stdin'],subprocess.DEVNULL)
            self.assertNotIn('DB_PASSWORD',kw['env']);self.assertNotIn('MYSQL_PWD',kw['env'])
            self.assertNotIn('--password',str(args));term.tcsetattr.assert_called_once_with(7,0,'saved');close.assert_called_once_with(7)
    def test_tty_echo_restored_after_timeout(self):
        term=self.collector_setup()
        with tempfile.TemporaryDirectory(dir=FIX) as directory,mock.patch.dict(sys.modules,{'termios':term}),mock.patch.object(h.os,'open',return_value=7),mock.patch.object(h.os,'close'),mock.patch.object(h.subprocess,'run',side_effect=subprocess.TimeoutExpired('php',1)):
            g=mock.Mock();g.wrap.side_effect=lambda x:x
            with self.assertRaises(subprocess.TimeoutExpired):h.collector('--check-readiness',pathlib.Path(directory),g)
            term.tcsetattr.assert_called_once_with(7,0,'saved')
if __name__=='__main__':unittest.main()
