import pathlib,tempfile,unittest,os,types,ast
from unittest import mock
code=pathlib.Path(__file__).with_name('deploy-reviewed-release.sh').read_text().split("<<'SULB_INVENTORY_PY'\n",1)[1].split('SULB_INVENTORY_PY',1)[0]
ns={'__name__':'closure_test'};exec(compile(code,'inventory','exec'),ns)
class ClosureInventory(unittest.TestCase):
 def test_readonly_inventory_has_no_mutation(self):
  tree=ast.parse(code);calls=[n.func.attr for n in ast.walk(tree)if isinstance(n,ast.Call)and isinstance(n.func,ast.Attribute)];self.assertFalse(set(calls)&{'unlink','remove','rmdir','rmtree','chmod','rename','write_text','write_bytes','mkdir','exec','system','run','Popen'})
 def test_symlink_not_followed(self):
  with tempfile.TemporaryDirectory()as d:
   p=pathlib.Path(d);(p/'data').write_bytes(b'x'*4096)
   try:(p/'link').symlink_to(p/'data')
   except OSError:self.skipTest('Windows symlink privilege unavailable')
   r=ns['footprint'](p);self.assertEqual(r['entries'],2)
 def test_hardlinks_not_marked_exclusive(self):
  with tempfile.TemporaryDirectory()as d:
   p=pathlib.Path(d);(p/'a').write_bytes(b'x'*4096);os.link(p/'a',p/'b');actual=os.lstat
   def allocated(path):
    st=actual(path);return types.SimpleNamespace(st_dev=st.st_dev,st_ino=st.st_ino,st_mode=st.st_mode,st_nlink=st.st_nlink,st_blocks=8)
   with mock.patch.object(os,'lstat',side_effect=allocated):r=ns['footprint'](p)
   self.assertEqual(r['allocated_file_bytes'],4096);self.assertEqual(r['exclusive_regular_file_bytes_upper_bound'],0)
 def test_entry_budget_fails_closed(self):
  with tempfile.TemporaryDirectory()as d:
   p=pathlib.Path(d);(p/'a').write_bytes(b'x');self.assertRaises(ValueError,ns['footprint'],p,0)
 def test_prod_names_never_work_candidates(self):
  for x in ['api.sulb.mgye.tech','sulbprod','mysql','sulb-history-keys','htdocs']:self.assertFalse(ns['PREFIX'].fullmatch(x))
 def test_reference_boundary(self):
  self.assertTrue(ns['overlaps']('/tmp/a','/tmp/a/data'));self.assertFalse(ns['overlaps']('/tmp/a','/tmp/ab/data'))
if __name__=='__main__':unittest.main()
