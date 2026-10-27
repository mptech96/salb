"""Mapping regression only; never accesses Linux processes, mounts, cgroups or DB."""
import importlib.util, pathlib, posixpath, unittest
from unittest import mock
from types import SimpleNamespace
spec=importlib.util.spec_from_file_location('isolation',pathlib.Path(__file__).with_name('close-native-isolation.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class Mapping(unittest.TestCase):
    def resolve(self,root,cwd=None): return m.verified_datadir([root+'/ibdata1',root+'/mysql.ibd'],cwd or root,posixpath.normpath)
    def test_standard_root(self): self.assertEqual(self.resolve('/var/lib/mysql')[0],'/var/lib/mysql')
    def test_custom_home_root(self): self.assertEqual(self.resolve('/home/mysql')[0],'/home/mysql')
    def test_custom_srv_root(self): self.assertEqual(self.resolve('/srv/erp-data')[0],'/srv/erp-data')
    def test_cwd_is_evidence_not_an_assumption(self): self.assertFalse(self.resolve('/home/mysql','/')[1]['cwd_matches_data_root'])
    def test_broad_root_refused(self):
        with self.assertRaisesRegex(m.Blocked,'MAPPING_REQUIRES_REVIEW'): self.resolve('/home')
    def test_site_root_refused(self):
        with self.assertRaisesRegex(m.Blocked,'MAPPING_REQUIRES_REVIEW'): self.resolve('/home/mgye-api-sulb')
    def test_htdocs_refused(self):
        with self.assertRaisesRegex(m.Blocked,'MAPPING_REQUIRES_REVIEW'): self.resolve('/home/site/htdocs/api')
    def test_no_dictionary_anchor_refused(self):
        with self.assertRaisesRegex(m.Blocked,'SECOND_ENGINE_ANCHOR'): m.verified_datadir(['/home/mysql/ibdata1'],'/home/mysql',posixpath.normpath)
    def test_ambiguous_roots_refused(self):
        with self.assertRaisesRegex(m.Blocked,'NOT_PROVEN'): m.verified_datadir(['/a/ibdata1','/b/ibdata1'],'/',posixpath.normpath)
    def test_deleted_anchor_refused(self):
        with self.assertRaisesRegex(m.Blocked,'NOT_PROVEN'): m.verified_datadir(['/home/mysql/ibdata1 (deleted)'],'/',posixpath.normpath)
    def test_aliases_resolved(self):
        alias=lambda p:posixpath.normpath(p).replace('/var/lib/mysql','/home/mysql')
        self.assertEqual(m.verified_datadir(['/var/lib/mysql/ibdata1','/home/mysql/mysql.ibd'],'/',alias)[0],'/home/mysql')
    def test_nested_socket_mount_collapsed(self): self.assertEqual(m.private_mount_targets('/home/mysql',['/home/mysql/mysql.sock']),['/home/mysql'])
    def test_broad_socket_parent_refused(self):
        with self.assertRaisesRegex(m.Blocked,'BROAD_MOUNT'): m.private_mount_targets('/home/mysql',['/home/mysql.sock'])
    def test_tmp_socket_private_mount_allowed(self): self.assertEqual(m.private_mount_targets('/home/mysql',['/tmp/mysql.sock']),['/home/mysql','/tmp'])
    def test_listening_fd_only(self):
        text='header\n0: 2 0 00010000 0001 01 111 /home/mysql/mysql.sock\n0: 2 0 00000000 0001 03 222 /run/journal.sock\n0: 2 0 00010000 0001 01 333 /tmp/foreign.sock'
        self.assertEqual(m.owned_listening_socket_paths(text,{'111','222'}),['/home/mysql/mysql.sock'])
    def test_abstract_listener_refused(self):
        with self.assertRaisesRegex(m.Blocked,'ABSTRACT'):m.owned_listening_socket_paths('header\n0: 2 0 00010000 0001 01 111 @mysql',{'111'})
    def test_changed_engine_file_identity_refused(self):
        fd=pathlib.Path('/proc/123/fd/99')
        with mock.patch.object(m.pathlib.Path,'iterdir',return_value=iter([fd])),mock.patch.object(m.os,'readlink',return_value='/home/mysql/ibdata1'),mock.patch.object(m.os,'stat',side_effect=[SimpleNamespace(st_dev=1,st_ino=10),SimpleNamespace(st_dev=1,st_ino=11)]):
            with self.assertRaisesRegex(m.Blocked,'ENGINE_ANCHOR_IDENTITY_CHANGED'):m.production_paths(123)
if __name__=='__main__': unittest.main()
