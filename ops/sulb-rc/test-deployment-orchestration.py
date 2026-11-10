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
 if pathlib.Path(args[0]).name=='composer':event('platform'if args[1]=='check-platform-reqs'else'composer');raise SystemExit(0)
 if args[0]=='-r':
  if 'PHP_MINOR_VERSION'in args[1]:event('runtime_version')
  if 'releaseWithReviewedFencePlan'in args[1]:event('runtime_binding')
  if 'random_bytes(8)'in args[1]:print('a'*16)
  elif 'COPY_STARTED'in args[1]:inside(args[2]).write_text('SYNTHETIC COPY MARKER')
  raise SystemExit(0)
 name=pathlib.Path(args[0]).name;mode=args[1]if len(args)>1 else''
 if name=='check-approval.php':event('gate')
 elif name=='release-app-control.php':event('manifest'if mode=='--prepare-candidate'else'cache')
 elif name=='release-process-fence.php':event('site_runtime')
 elif name=='release-fence.php':
  key={'--acquire':'fence','--refresh-workers':'refresh','--release':'release','--resume-workers':'resume','--verify-readiness':'http_ready'}[mode];event(key)
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
 if args[:2]==['env','-i'] and '/usr/bin/test'in args:
  raise SystemExit(0 if os.access(inside(args[-1]),os.X_OK) else 1)
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
 if args[0]=='--version':event('npm_version');raise SystemExit(0)
 if args[0]=='ci'and'--include=dev'not in args:raise RuntimeError('MISSING_BUILD_TOOLS')
 event('npm_'+args[0])
elif cmd=='node':event('node_version')
elif cmd=='rsync':inside(args[-1]);event('copy')
elif cmd=='systemctl':
 if 'show'in args:print('123' if '--property=MainPID'in args else 'mgye-sulb')
 else:event('frontend_restart')
elif cmd=='curl':event('smoke')
elif cmd=='mkdir':inside(args[-1]).mkdir(mode=0o700)
elif cmd=='id':print('0'if'-u'in args else'synthetic_group')
elif cmd=='uname':print('Linux')
elif cmd=='stat':print('0')
elif cmd=='command':raise RuntimeError('UNEXPECTED_COMMAND')
elif cmd=='git':
 if 'rev-parse'in args:print('b'*40)
elif cmd=='dirname':print(str(pathlib.Path(args[-1]).parent))
elif cmd=='realpath':print(str(root/'site-bin'/('other-node' if case['fail']=='node_mismatch' else 'node') if args[-1].endswith('/123/exe') else inside(args[-1])))
elif cmd=='flock':event('execution_lock')
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
 def run_case(self,failure='',preexisting=''):
  with tempfile.TemporaryDirectory()as temporary:
   root=pathlib.Path(temporary);(root/'bin').mkdir();(root/'site-bin').mkdir();(root/'proc/123').mkdir(parents=True);(root/'proc/123/environ').write_bytes(b'OTHER=private\0PATH='+os.fsencode(root/'site-bin')+b'\0');(root/'case.json').write_text(json.dumps({'fail':failure}))
   for name in ['php','python3','runuser','env','composer','npm','node','rsync','systemctl','curl','mkdir','id','uname','stat','git','dirname','realpath','sha256sum','cut','awk','df','chown','chmod','find','tar','flock']:
    file=root/('site-bin' if name in ('node','npm') else 'bin')/name;file.write_text(SHIM.replace('__PYTHON__',sys.executable));file.chmod(0o700)
   if preexisting=='missing-npm':(root/'site-bin/npm').unlink()
   if preexisting=='fence':(root/'fence').mkdir()
   checkout=root/'checkout';(checkout/'ops/sulb-rc').mkdir(parents=True);(checkout/'backend/bootstrap/cache').mkdir(parents=True);(checkout/'frontend').mkdir();(checkout/'.git').mkdir();(root/'api').mkdir();(root/'front').mkdir()
   if preexisting=='partial':
    prior=root/'api/sulb-release-backup.incomplete';prior.mkdir();(prior/'ordered-upgrade-events.jsonl').touch()
   text=pathlib.Path(__file__).with_name('deploy-reviewed-release.sh').read_text();
   for old,new in [('/home/mgye-sulb/.nvm/versions/node/v22.23.2/bin',str(root/'site-bin')),('/home/sulb-release',str(checkout)),('/home/mgye-api-sulb',str(root/'api')),('/home/mgye-sulb',str(root/'front')),('/run/sulb-release.fence',str(root/'fence')),('/run/sulb-release.execution.lock',str(root/'lock')),('/proc/$pid',str(root/'proc')+'/$pid')]:text=text.replace(old,new)
   script=checkout/'ops/sulb-rc/deploy.sh';script.write_text(text)
   env={'PATH':str(root/'bin'),'SULB_PHP_BIN':str(root/'bin/php'),'SULB_APPROVED_COMMIT':'b'*40,'SULB_APPROVED_DERIVED_ARTIFACT':str(root/'SYNTHETIC_ONLY')}
   r=subprocess.run(['/bin/bash',str(script),'--execute'],cwd=checkout,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=45)
   self.last_stderr=r.stderr.decode()
   events=[json.loads(line)for line in (root/'events.jsonl').read_text().splitlines()];return r.returncode,events,(root/'fence').exists()
 def test_complete_order_only_with_successful_restore(self):
  code,e,fence=self.run_case();self.assertEqual(code,0);self.assertFalse(fence)
  order=['gate','site_runtime','scope','composer','npm_ci','npm_run','manifest','fence','capture','restore','receipt','migration','copy','cache','postcheck','activation','frontend_restart','http_ready','release']
  positions=[e.index(x)for x in order];self.assertEqual(positions,sorted(positions))
 def test_frontend_tools_do_not_require_root_path(self):
  code,e,fence=self.run_case();self.assertEqual(code,0);self.assertLess(e.index('node_version'),e.index('composer'));self.assertIn('npm_version',e)
 def test_service_node_mismatch_blocks_before_fencing(self):
  code,e,fence=self.run_case('node_mismatch');self.assertNotEqual(code,0);self.assertFalse(fence);self.assertNotIn('composer',e);self.assertIn('FRONTEND_NODE_SERVICE_MISMATCH',self.last_stderr)
 def test_missing_site_npm_reports_reason_before_fencing(self):
  code,e,fence=self.run_case(preexisting='missing-npm');self.assertNotEqual(code,0);self.assertFalse(fence);self.assertNotIn('composer',e);self.assertIn('FRONTEND_NPM_MISSING',self.last_stderr)
 def test_existing_fence_blocks_another_deployment(self):
  code,e,fence=self.run_case(preexisting='fence');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertNotIn('composer',e);self.assertIn('WRITE_FENCE_ALREADY_PRESENT',self.last_stderr)
 def test_partial_upgrade_journal_blocks_retry(self):
  code,e,fence=self.run_case(preexisting='partial');self.assertNotEqual(code,0);self.assertFalse(fence);self.assertNotIn('composer',e);self.assertIn('PREVIOUS_RELEASE_REQUIRES_REVIEW',self.last_stderr)
 def test_gate_rejects_before_build_or_maintenance(self):
  code,e,fence=self.run_case('gate');self.assertNotEqual(code,0);self.assertEqual(e,['gate']);self.assertFalse(fence)
 def test_wrong_php_stops_before_build_or_fence(self):
  code,e,fence=self.run_case('runtime_version');self.assertNotEqual(code,0);self.assertFalse(fence);self.assertNotIn('composer',e);self.assertNotIn('fence',e)
 def test_wrong_fpm_binding_stops_before_build_or_fence(self):
  code,e,fence=self.run_case('runtime_binding');self.assertNotEqual(code,0);self.assertFalse(fence);self.assertNotIn('composer',e);self.assertNotIn('fence',e)
 def test_restore_failure_never_copies_or_migrates(self):
  code,e,fence=self.run_case('restore');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertNotIn('copy',e);self.assertNotIn('migration',e)
 def test_receipt_failure_never_copies(self):
  code,e,fence=self.run_case('receipt');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertNotIn('copy',e)
 def test_migration_failure_never_activates_or_releases(self):
  code,e,fence=self.run_case('migration');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertNotIn('copy',e);self.assertNotIn('activation',e);self.assertNotIn('release',e)
 def test_postcheck_failure_never_releases(self):
  code,e,fence=self.run_case('postcheck');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertNotIn('release',e)
 def test_runtime_cache_policy_failure_prevents_downtime(self):
  code,e,fence=self.run_case('site_runtime');self.assertNotEqual(code,0);self.assertFalse(fence);self.assertNotIn('fence',e)
 def test_frontend_start_failure_keeps_fence(self):
  code,e,fence=self.run_case('frontend_restart');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertNotIn('release',e)
 def test_http_readiness_failure_keeps_fence_after_ddl(self):
  code,e,fence=self.run_case('http_ready');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertIn('migration',e);self.assertNotIn('release',e)
 def test_unfence_is_the_last_operational_event(self):
  code,e,fence=self.run_case();self.assertEqual(code,0);self.assertEqual(e[-1],'release');self.assertNotIn('resume',e);self.assertNotIn('smoke',e)
 def test_refresh_failure_after_copy_retains_fence(self):
  code,e,fence=self.run_case('refresh');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertIn('migration',e);self.assertNotIn('release',e)
 def test_release_failure_does_not_run_later_operations(self):
  code,e,fence=self.run_case('release');self.assertNotEqual(code,0);self.assertTrue(fence);self.assertEqual(e[-1],'release')
if __name__=='__main__':unittest.main()
