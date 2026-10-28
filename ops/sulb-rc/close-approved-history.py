"""One post-accounting command. No production extraction, writes or deployment."""
import pathlib,json,subprocess,sys,importlib.util
spec=importlib.util.spec_from_file_location('stage',pathlib.Path(__file__).with_name('history-stage.py'));s=importlib.util.module_from_spec(spec);spec.loader.exec_module(s)
def main(sha):
 p=json.loads(pathlib.Path(__file__).with_name('release.json').read_text());missing=[k for k in ['legacy_allocation_accounting_approval','inventory_account_mapping_approval']if p.get(k,{}).get('authorized')is not True or p[k].get('packet_sha256')!=sha]
 if missing:print(json.dumps({'closure':'BLOCKED','reason':'TWO_EXPLICIT_HISTORY_APPROVALS_REQUIRED','missing':missing,'production_connected':False,'restore_started':False,'release_decision':'NO-GO'}));return 78
 r=subprocess.run(['php',str(pathlib.Path(__file__).with_name('record-history-approvals.php')),sha],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=3600)
 try:v=json.loads(r.stdout)
 except Exception:print(json.dumps({'closure':'BLOCKED','reason':'PRIVATE_APPROVAL_RECORD_FAILURE'}));return 78
 if r.returncode:print(json.dumps(v));return 78
 work=pathlib.Path(v['archive_directory']);return s.main(['--approved-backfill',str(work)])
if __name__=='__main__':
 if len(sys.argv)!=2:raise SystemExit('EXACT_PACKET_SHA_REQUIRED')
 raise SystemExit(main(sys.argv[1]))
