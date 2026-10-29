"""Run ONLY a relocated script copy with an allowlisted fake toolchain; no real PHP/SQL/network/services."""
import json,os,pathlib,subprocess,sys,tempfile,unittest
SHIM=r'''#!__PYTHON__
import json,os,pathlib,subprocess,sys
root=pathlib.Path(__file__).parent.parent
case=json.loads((root/'case.json').read_text());cmd=pathlib.Path(sys.argv[0]).name;args=sys.argv[1:]
def event(name):
 with (root/'events.jsonl').open('a')as f:f.write(json.dumps(name)+'\n')
 if case['fail']==name:raise SystemExit(78)
def inside(path):
 p=pathlib.Path(path).resolve()
 if p!=root and root not in p.parents:raise RuntimeError('TEST_PATH_ESCAPE')
 return p
if cmd=='php':
 if not args:sys.stdin.read();event('scope');raise SystemExit(0)
 if args[0]=='-r':
  if 'random_bytes(8)'in args[1]:print('a'*16)
  elif 'COPY_STARTED'in args[1]:inside(args[2]).write_text('SYNTHETIC COPY MARKER')
  raise SystemExit(0)
 name=pathlib.Path(args[0]).name;mode=args[1]if len(args)>1 else''
 if name=='check-approval.php':event('gate')
 elif name=='release-app-control.php':event('manifest'if mode=='--prepare-candidate'else'cache')
 elif name=='release-fence.php':
  key={'--acquire':'fence','--refresh-workers':'refresh','--release':'release','--resume-workers':'resume'}[mode];event(key)
  if mode=='--acquire':(root/'fence').mkdir();(root/'fence/permit.json').write_text('SYNTHETIC ONLY')
  if mode=='--release':(root/'fence/permit.json').unlink();(root/'fence').rmdir()
 elif name=='release-backup.php':event('capture'if mode=='--capture'else'receipt')
 elif name=='production-upgrade.php':event('migration')
 elif name=='release-postcheck.php':event('postcheck')
 elif name=='release-activate.php':event('activation')
 else:raise RuntimeError('UNMODELED_PHP_HELPER')
elif cmd=='python3':
 if pathlib.Path(args[0]).name!='history-stage.py':raise RuntimeError('UNMODELED_PYTHON')
 event('restore')
elif cmd=='runuser':
 args=args[args.index('--')+1:]
 if args[:2]==['test','-x']:inside(args[2]);raise SystemExit(0)
 raise SystemExit(subprocess.run(args).returncode)
elif cmd=='env':
 clean={}if args and args[0]=='-i'else dict(os.environ)
 if args and args[0]=='-i':args=args[1:]
 while args and '='in args[0]:k,v=args.pop(0).split('=',1);clean[k]=v
 raise SystemExit(subprocess.run(args,env=clean).returncode)
elif cmd=='composer':
 if '--no-scripts'not in args or '--no-plugins'not in args:raise RuntimeError('UNSAFE_COMPOSER')
 event('composer')
elif cmd=='npm':
 if args[0]=='ci'and'--include=dev'not in args:raise RuntimeError('MISSING_BUILD_TOOLS')
 event('npm_'+args[0])
elif cmd=='node':event('node_version')
elif cmd=='rsync':inside(args[-1]);event('copy')
elif cmd=='systemctl':
 if 'show'in args:print('mgye-sulb')
 else:event('frontend_restart')
elif cmd=='curl':event('smoke')
elif cmd=='mkdir':inside(args[-1]).mkdir(mode=0o700)
elif cmd=='id':print('0'if'-u'in args else'synthetic_group')
elif cmd=='uname':print('Linux')
elif cmd=='stat':print('0')
elif cmd=='git':
 if 'rev-parse'in args:print('b'*40)
elif cmd=='dirname':print(str(pathlib.Path(args[-1]).parent))
elif cmd=='realpath':print(str(inside(args[-1])))
elif cmd=='sha256sum':print('c'*64+'  SYNTHETIC')
elif cmd=='cut':sys.stdin.read();print('c'*64)
elif cmd=='awk':
 if any('meminfo'in a for a in args):print('8000000')
 else:sys.stdin.read();print('200000000')
elif cmd=='df':print('SYNTHETIC DISK ONLY')
elif cmd in ['chown','chmod','find','tar']:event('generated_permissions')
else:raise RuntimeError('UNMODELED_COMMAND:'+cmd)
'''
@unittest.skipUnless(sys.platform=='linux','Relocated fake-toolchain integration requires Linux Bash')
class DeploymentOrdering(unittest.TestCase):
 def run_case(self,failure=''):
  with tempfile.TemporaryDirectory()as temporary:
   root=pathlib.Path(temporary);(root/'bin').mkdir();(root/'case.json').write_text(json.dumps({'fail':failure}))
   for name in ['php','python3','runuser','env','composer','npm','node','rsync','systemctl','curl','mkdir','id','uname','stat','git','dirname','realpath','sha256sum','cut','awk','df','chown','chmod','find','tar']:
    file=root/'bin'/name;file.write_text(SHIM.replace('__PYTHON__',sys.executable));file.chmod(0o700)
   checkout=root/'checkout';(checkout/'ops/sulb-rc').mkdir(parents=True);(checkout/'backend/bootstrap/cache').mkdir(parents=True);(checkout/'frontend').mkdir();(checkout/'.git').mkdir();(root/'api').mkdir();(root/'front').mkdir()
   text=pathlib.Path(__file__).with_name('deploy-reviewed-release.sh').read_text();
   for old,new in [('/home/sulb-release',str(checkout)),('/home/mgye-api-sulb',str(root/'api')),('/home/mgye-sulb',str(root/'front')),('/run/sulb-release.fence',str(root/'fence'))]:text=text.replace(old,new)
   script=checkout/'ops/sulb-rc/deploy.sh';script.write_text(text)
   env={'PATH':str(root/'bin'),'SULB_APPROVED_COMMIT':'b'*40,'SULB_APPROVED_DERIVED_ARTIFACT':str(root/'SYNTHETIC_ONLY')}
   r=subprocess.run(['/bin/bash',str(script),'--execute'],cwd=checkout,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=45)
   events=[json.loads(line)for line in (root/'events.jsonl').read_text().splitlines()];return r.returncode,events,(root/'fence').exists()
 def test_complete_order_only_with_successful_restore(self):
  code,e,fence=self.run_case();self.assertEqual(code,0);self.assertFalse(fence)
  order=['gate','scope','composer','npm_ci','npm_run','manifest','fence','capture','restore','receipt','copy','cache','migration','postcheck','activation','release','resume','frontend_restart','smoke']
  positions=[e.index(x)for x in order];self.assertEqual(positions,sorted(positions))
 def test_gate_rejects_before_build_or_maintenance(self):
  code,e,fence=self.run_case('gate');self.assertNotEqual(code,0);self.assertEqual(e,['gate']);self.assertFalse(fence)
 def test_restore_failure_never_copies_or_migrates(self):
  code,e,fence=self.run_case('restore');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertNotIn('copy',e);self.assertNotIn('migration',e)
 def test_receipt_failure_never_copies(self):
  code,e,fence=self.run_case('receipt');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertNotIn('copy',e)
 def test_migration_failure_never_activates_or_releases(self):
  code,e,fence=self.run_case('migration');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertNotIn('activation',e);self.assertNotIn('release',e)
 def test_postcheck_failure_never_releases(self):
  code,e,fence=self.run_case('postcheck');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertNotIn('release',e)
if __name__=='__main__':unittest.main()
