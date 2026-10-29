"""One post-accounting command. No production extraction, writes or deployment."""
import pathlib,json,subprocess,sys,importlib.util,contextlib,io
spec=importlib.util.spec_from_file_location('stage',pathlib.Path(__file__).with_name('history-stage.py'));s=importlib.util.module_from_spec(spec);spec.loader.exec_module(s)
def main(sha,review_writers=False):
 p=json.loads(pathlib.Path(__file__).with_name('release.json').read_text());missing=[k for k in ['legacy_allocation_accounting_approval','inventory_account_mapping_approval']if p.get(k,{}).get('authorized')is not True or p[k].get('packet_sha256')!=sha]
 if missing:print(json.dumps({'closure':'BLOCKED','reason':'TWO_EXPLICIT_HISTORY_APPROVALS_REQUIRED','missing':missing,'production_connected':False,'restore_started':False,'release_decision':'NO-GO'}));return 78
 r=subprocess.run(['php',str(pathlib.Path(__file__).with_name('record-history-approvals.php')),sha],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=3600)
 try:v=json.loads(r.stdout)
 except Exception:print(json.dumps({'closure':'BLOCKED','reason':'PRIVATE_APPROVAL_RECORD_FAILURE'}));return 78
 if r.returncode:print(json.dumps(v));return 78
 work=pathlib.Path(v['archive_directory'])
 if not review_writers:return s.main(['--approved-backfill',str(work)])
 with contextlib.redirect_stdout(io.StringIO())as captured:code=s.main(['--approved-backfill',str(work)])
 try:history=json.loads(captured.getvalue())
 except Exception:print(json.dumps({'closure':'BLOCKED','reason':'PRIVATE_HISTORY_RESULT_INVALID'}));return 78
 if code:print(json.dumps(history));return code
 proc=subprocess.run(['php',str(pathlib.Path(__file__).with_name('release-fence.php')),'--review-profile'],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=180)
 try:profile=json.loads(proc.stdout)
 except Exception:profile={'writer_review':'BLOCKED','reason':'PRIVATE_WRITER_REVIEW_RESULT_INVALID'}
 print(json.dumps({'historical':history,'writer_review':profile,'release_decision':'NO-GO','deployment_performed':False},indent=2))
 return proc.returncode

if __name__=='__main__':
 if len(sys.argv)not in [2,3]or(len(sys.argv)==3 and sys.argv[2]!='--review-writers'):raise SystemExit('EXACT_PACKET_SHA_REQUIRED')
 raise SystemExit(main(sys.argv[1],len(sys.argv)==3))
