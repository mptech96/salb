import ast,copy,io,hashlib,importlib.util,json,os,pathlib,secrets,sys,unittest
spec=importlib.util.spec_from_file_location('packet',pathlib.Path(__file__).with_name('prepare-writer-signoff.py'));m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def fixture():
 accounts=[{'principal':'SYNTHETIC_ACCOUNT_'+str(i)+'@localhost','locked':i>=5,'can_write_sulbprod':i<5,'global_write_or_admin':i<4,'capability_evidence':['SOURCE_SCHEMA:Insert_priv']if i<5 else[]}for i in range(9)]
 rows=[{'principal':a['principal'],'locked':a['locked'],'potential_source_writer':a['can_write_sulbprod'],'privileged':a['global_write_or_admin'],'approved':False,'decision':None,'observed_capabilities':a['capability_evidence'],'matching_live_session_ids':[],'declared_application_candidate':i==4}for i,a in enumerate(accounts)]
 return {'format':'SULB_PRIVATE_WRITER_PROFILE_V1','database_accounts':accounts,'captured_utc':'2026-10-29T00:00:00Z','writer_linkage':{'config_binding':{'status':'ENV_DECLARATION_ONLY'},'accounts_for_explicit_review':rows,'missing_proofs':list(m.REASONS),'service_schedule_candidates':[{'path':'/etc/cron.d/SYNTHETIC_ONLY','sha256':'a'*64,'approved':False,'candidate_principals':[accounts[4]['principal']]}],'process_unit_evidence':[],'effective_configuration_verified':False,'external_writers_and_schedules_reviewed':False,'automatically_approved':False,'source_inventory_is_exhaustive_live_proof':False}}
class PacketTests(unittest.TestCase):
 def test_unsigned_and_bound(self):
  p=fixture();original=copy.deepcopy(p);facts,draft,summary=m.packet(p,'b'*64);self.assertEqual(p,original);self.assertEqual(draft['evidence_sha256'],hashlib.sha256(m.canonical(facts)).hexdigest());self.assertFalse(draft['approved']);self.assertEqual(summary['accounts'],9);self.assertEqual(summary['authenticated_relations_proven'],0);self.assertTrue(all(a['decision']is None for a in draft['accounts']))
 def test_safe_summary_contains_no_principal_or_service(self):
  f,d,s=m.packet(fixture(),'b'*64);text=json.dumps(s);self.assertNotIn('SYNTHETIC_ACCOUNT',text);self.assertNotIn('/etc/cron',text);self.assertEqual(s['database_connections'],0)
 def test_missing_five_categories(self):
  p=fixture();p['writer_linkage']['missing_proofs'].pop()
  with self.assertRaises(m.Blocked):m.packet(p,'b'*64)
 def test_duplicate_principal(self):
  p=fixture();p['database_accounts'][1]['principal']=p['database_accounts'][0]['principal']
  with self.assertRaises(m.Blocked):m.packet(p,'b'*64)
 def test_membership_mismatch(self):
  p=fixture();p['writer_linkage']['accounts_for_explicit_review'][0]['principal']='other'
  with self.assertRaises(m.Blocked):m.packet(p,'b'*64)
 def test_changed_capabilities(self):
  p=fixture();p['writer_linkage']['accounts_for_explicit_review'][0]['observed_capabilities']=['CHANGED']
  with self.assertRaises(m.Blocked):m.packet(p,'b'*64)
 def test_existing_decision_never_overwritten(self):
  p=fixture();p['writer_linkage']['accounts_for_explicit_review'][0]['decision']='APPROVE'
  with self.assertRaises(m.Blocked):m.packet(p,'b'*64)
 def test_no_conversion_to_verified(self):
  p=fixture();p['writer_linkage']['effective_configuration_verified']=True
  with self.assertRaises(m.Blocked):m.packet(p,'b'*64)
 def test_unknown_service_principal(self):
  p=fixture();p['writer_linkage']['service_schedule_candidates'][0]['candidate_principals']=['unknown']
  with self.assertRaises(m.Blocked):m.packet(p,'b'*64)
 def test_counts_not_inferred(self):
  p=fixture();p['database_accounts'][0]['locked']=True;p['writer_linkage']['accounts_for_explicit_review'][0]['locked']=True
  with self.assertRaises(m.Blocked):m.packet(p,'b'*64)
 def test_report_private_no_absence_inference(self):
  f,d,s=m.packet(fixture(),'b'*64);report=m.private_report(f).decode();self.assertIn('SYNTHETIC_ACCOUNT',report);self.assertTrue(f['absence_of_process_is_not_absence_of_writer']);self.assertFalse(d['deployment_authorized'])
 def test_no_database_network_or_process_modules(self):
  tree=ast.parse(pathlib.Path(m.__file__).read_text(encoding='utf-8'));names={n.name.split('.')[0]for node in ast.walk(tree)if isinstance(node,(ast.Import,ast.ImportFrom))for n in (node.names if isinstance(node,ast.Import)else [])};self.assertFalse(names&{'subprocess','socket','urllib','requests','pymysql','sqlite3'})
class InputFailureTests(unittest.TestCase):
 def test_explicit_prompt_inputs(self):
  prompts=io.StringIO();self.assertEqual(m.prompted_inputs(io.StringIO('/PRIVATE_PROFILE\n'+'a'*64+'\n'),prompts),('/PRIVATE_PROFILE','a'*64))
 def test_missing_path_is_not_generic_error(self):
  with self.assertRaisesRegex(m.Blocked,'PROFILE_PATH_REQUIRED'):m.prompted_inputs(io.StringIO('\n'),io.StringIO())
 def test_missing_sha_is_not_generic_error(self):
  with self.assertRaisesRegex(m.Blocked,'PROFILE_SHA_REQUIRED'):m.prompted_inputs(io.StringIO('/PRIVATE\n\n'),io.StringIO())
 def test_error_messages_never_export_paths_or_content(self):
  secret='SYNTHETIC_SECRET_DO_NOT_PRINT';errors=[(FileNotFoundError(2,secret,secret),'READ_PROFILE','PRIVATE_PROFILE_NOT_FOUND'),(PermissionError(13,secret,secret),'READ_PROFILE','PRIVATE_FILE_ACCESS_DENIED'),(json.JSONDecodeError(secret,secret,0),'READ_PROFILE','PRIVATE_PROFILE_JSON_INVALID'),(TypeError(secret),'VALIDATE_PACKET','PRIVATE_PROFILE_STRUCTURE_INVALID'),(OSError(28,secret),'WRITE_PACKET','PRIVATE_PACKET_WRITE_FAILED'),(OSError(6,secret),'READ_OPERATOR_INPUT','OPERATOR_TTY_INPUT_UNAVAILABLE')]
  for error,stage,reason in errors:
   value=m.safe_failure(error,stage);self.assertEqual(value['reason'],reason);self.assertNotIn(secret,json.dumps(value));self.assertEqual(value['database_connections'],0)
@unittest.skipUnless(sys.platform=='linux','actual pseudoterminal regression requires Linux')
class TerminalTests(unittest.TestCase):
 def test_old_random_access_terminal_open_reproduces_failure(self):
  import pty
  master,slave=pty.openpty()
  try:
   os.write(master,b'SYNTHETIC_INPUT\n')
   with self.assertRaises((io.UnsupportedOperation,OSError))as failure:
    with open(os.ttyname(slave),'r+')as terminal:terminal.write('PROMPT');terminal.flush();terminal.readline()
   self.assertIn('seek',str(failure.exception).lower());print('REPRODUCED_OLD_TTY_FAILURE='+type(failure.exception).__name__+'; seek_failure=true')
  finally:os.close(master);os.close(slave)
 def test_separate_stream_terminal_input(self):
  import pty
  master,slave=pty.openpty()
  try:
   os.write(master,('/PRIVATE_PROFILE\n'+'a'*64+'\n').encode());self.assertEqual(m.tty_inputs(os.ttyname(slave)),('/PRIVATE_PROFILE','a'*64))
  finally:os.close(master);os.close(slave)
@unittest.skipUnless(sys.platform=='linux'and os.geteuid()==0 and os.environ.get('GITHUB_ACTIONS')=='true','private filesystem fixtures require isolated Linux CI root')
class PrivateFiles(unittest.TestCase):
 def setUp(self):
  self.dir=pathlib.Path('/home/mgye-api-sulb')/('sulb-release-review-'+secrets.token_hex(8));self.dir.mkdir(mode=0o700,parents=True);self.path=self.dir/'profile-private.json';self.data=m.canonical(fixture());self.path.write_bytes(self.data);self.path.chmod(0o600);self.sha=hashlib.sha256(self.data).hexdigest()
 def test_read_exact_private_source(self):self.assertEqual(m.private_source(str(self.path),self.sha),fixture())
 def test_hash_mismatch(self):
  with self.assertRaises(m.Blocked):m.private_source(str(self.path),'0'*64)
 def test_public_permissions_rejected(self):
  self.path.chmod(0o644)
  with self.assertRaises(m.Blocked):m.private_source(str(self.path),self.sha)
 def test_exclusive_write_never_overwrites(self):
  with self.assertRaises(FileExistsError):m.write_private(self.path,b'REPLACE')
  self.assertEqual(self.path.read_bytes(),self.data)
if __name__=='__main__':unittest.main()
