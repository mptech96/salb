"""New targeted diagnostics tests only; no Linux resources or production access."""
import errno, pathlib, subprocess, unittest
from unittest import mock
from types import SimpleNamespace
import native_isolation_checks as m
REPLY=b'allow 0xffffffff\ndeny 0x00000000\naudit 0x00000000\nquiet 0x00000000\n'
class Diagnostics(unittest.TestCase):
    def test_full_kernel_payload_preserves_socket_suffix(self):
        self.assertEqual(m.file_access_payload('mysqld','/run/mysqld/stage.sock'),b'label\0mysqld\0\x02/run/mysqld/stage.sock')
    def test_nul_injection_refused(self):
        with self.assertRaises(m.CheckBlocked):m.file_access_payload('mysqld\0bad','/qa')
    def test_complete_reply(self):self.assertEqual(m.parse_access_reply(REPLY)['allow'],0xffffffff)
    def test_duplicate_reply_refused(self):
        with self.assertRaises(m.CheckBlocked):m.parse_access_reply(REPLY+b'allow 0xffffffff\n')
    def test_missing_reply_refused(self):
        with self.assertRaises(m.CheckBlocked):m.parse_access_reply(b'allow 0xffffffff\n')
    def test_missing_allow_is_not_called_explicit_deny(self):
        r=m.policy_row('mysqld','/qa',['read','write'],lambda *a:{'allow':4,'deny':0})
        self.assertEqual(r['explicit_denied_operations'],[]);self.assertEqual(r['missing_explicit_allow'],['write']);self.assertFalse(r['strictly_allowed'])
    def test_exact_explicit_deny_identified(self):
        r=m.policy_row('mysqld','/qa',['read','write'],lambda *a:{'allow':6,'deny':2})
        self.assertEqual(r['explicit_denied_operations'],['write']);self.assertFalse(r['strictly_allowed'])
    def test_allowed_plan_uses_existing_mapping(self):
        r=m.policy_check('mysqld (complain)','/home/mysql',['/run/mysqld/mysql.sock'],'a'*32,lambda *a:{'allow':0xffffffff,'deny':0})
        self.assertEqual(r['selected_logical_datadir'],'/home/mysql')
    def test_policy_compatible_alternative_requires_proof(self):
        def q(label,path):return {'allow':0xffffffff,'deny':2 if path.startswith('/home/') else 0}
        r=m.policy_check('mysqld (complain)','/home/mysql',['/home/mysql/mysql.sock'],'a'*32,q)
        self.assertEqual(r['selected_logical_datadir'],'/var/lib/mysql');self.assertEqual(r['selected_socket_parent'],'/run/mysqld');self.assertTrue(r['plans'][-1]['requires_new_private_mount_proof'])
    def test_no_policy_exception_when_all_plans_denied(self):
        with self.assertRaises(m.CheckBlocked)as error:m.policy_check('mysqld (complain)','/home/mysql',['/run/mysqld/mysql.sock'],'a'*32,lambda *a:{'allow':0xffffffff,'deny':2})
        self.assertEqual(error.exception.code,'APPARMOR_NO_PROVEN_ALLOWED_PLAN');self.assertEqual(len(error.exception.evidence['plans']),2)
    def test_unconfined_not_accepted(self):
        with self.assertRaises(m.CheckBlocked):m.policy_check('unconfined','/home/mysql',[],'a'*32)
    def test_io_partition_maps_to_parent(self):self.assertEqual(m.io_target('8:1','8:0'),'8:0')
    def test_io_mapper_device_retained(self):self.assertEqual(m.io_target('253:0'),'253:0')
    def test_overlay_not_falsely_qualified(self):
        with self.assertRaises(m.CheckBlocked):m.io_target('0:17')
    def test_permission_error_identifies_control_and_errno(self):
        r=m.exception_evidence('write_memory.max',PermissionError(errno.EACCES,'DO_NOT_PRINT'))
        self.assertEqual(r['phase'],'write_memory.max');self.assertEqual(r['errno_name'],'EACCES');self.assertEqual(r['classification'],'HOST_PERMISSION_OR_READONLY');self.assertNotIn('DO_NOT_PRINT',str(r))
    def test_einval_is_not_called_unsupported_host(self):self.assertEqual(m.exception_evidence('write_io.max',OSError(errno.EINVAL,'qa'))['classification'],'REQUEST_OR_RUNTIME_FAILURE_NOT_PROOF_OF_UNSUPPORTED_HOST')
    def test_child_error_is_sanitized(self):
        r=m.exception_evidence('direct_io_probe',subprocess.CalledProcessError(1,['dd'],stderr=b'PRIVATE_ARGS Permission denied SECRET'))
        self.assertEqual(r['stderr_kind'],'Permission denied');self.assertNotIn('SECRET',str(r))
    def test_cgroup_creation_permission_preserves_primary_failure(self):
        root=mock.MagicMock();control=mock.MagicMock();cg=mock.MagicMock();dev=mock.MagicMock()
        control.read_text.return_value='memory cpu pids io';root.__truediv__.side_effect=lambda key:control if key=='cgroup.subtree_control' else cg
        dev.resolve.return_value=dev;(dev/'partition').exists.return_value=False;dev.exists.return_value=True
        cg.mkdir.side_effect=PermissionError(errno.EACCES,'not printed')
        def path(*args):return root if args==('/sys/fs/cgroup',) else dev
        with mock.patch.object(m,'pathlib',SimpleNamespace(Path=path)),mock.patch.object(m.subprocess,'check_output',return_value=b'8:1\n'):
            with self.assertRaises(m.CheckBlocked)as error:m.cgroup_check(pathlib.Path('fake-private'))
        self.assertEqual(error.exception.evidence['failure']['phase'],'create_owned_cgroup');self.assertEqual(error.exception.evidence['failure']['errno'],errno.EACCES);cg.rmdir.assert_not_called()
if __name__=='__main__':unittest.main()
