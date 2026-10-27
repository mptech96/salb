"""Single reviewed history execution entry. No approval, no rows, no credentials."""
import contextlib, hashlib, importlib.util, io, json, pathlib, re, sys, time
spec=importlib.util.spec_from_file_location('stage',pathlib.Path(__file__).with_name('history-stage.py'))
s=importlib.util.module_from_spec(spec);spec.loader.exec_module(s)

def approved_receipt(sha):
    if not re.fullmatch('[a-f0-9]{64}',sha):raise s.CheckBlocked('HISTORY_PLAN_SHA_REQUIRED')
    matches=[]
    for directory in list(s.BASE.glob('sulb-history-ready-*'))[:2048]:
        try:
            s.secure_directory(directory,'sulb-history-ready-');p=directory/'readiness-private.json'
            if p.is_symlink() or not p.is_file() or p.stat().st_size>65536 or p.stat().st_uid!=0 or p.stat().st_mode&0o077:continue
            if hashlib.sha256(p.read_bytes()).hexdigest()==sha:matches.append(directory)
        except (OSError,s.CheckBlocked):continue
    if len(matches)!=1:raise s.CheckBlocked('HISTORY_APPROVED_RECEIPT_NOT_UNIQUE_OR_MISSING')
    return matches[0]

def execute(sha):
    result={'historical_experiment':'BLOCKED','production_rows_extraction_authorized':False,'release_decision':'NO-GO'}
    try:
        release=json.loads(pathlib.Path(__file__).with_name('release.json').read_text())
        if release.get('history_capture',{}).get('approved_readiness_sha256')!=sha:raise s.CheckBlocked('HISTORY_PLAN_SHA_NOT_REVIEWED')
        if release.get('history_plan',{}).get('execution_authorized') is not True or release['history_capture'].get('production_extraction_authorized') is not True:raise s.CheckBlocked('HISTORY_PLAN_AWAITS_EXPLICIT_EXECUTION_APPROVAL')
        at=release.get('history_plan',{}).get('execution_approved_unix')
        if not isinstance(at,int) or at>time.time() or time.time()-at>3600:raise s.CheckBlocked('HISTORY_PLAN_EXECUTION_WINDOW_EXPIRED')
        work=approved_receipt(sha);result['readiness_sha256']=sha
        if int(next(x.split()[1] for x in pathlib.Path('/proc/meminfo').read_text().splitlines() if x.startswith('MemAvailable:')))*1024<3221225472:raise s.CheckBlocked('HISTORY_PLAN_RESTORE_HEADROOM_REQUIRED')
        # No automatic second extraction. Preserve incomplete attempts for review.
        if not (work/'manifest-private.json').is_file():
            if (work/'snapshot.enc').exists():raise s.CheckBlocked('HISTORY_PARTIAL_SNAPSHOT_REVIEW_REQUIRED')
            with contextlib.redirect_stdout(io.StringIO()) as output:code=s.main(['--capture-approved',str(work)])
            capture=json.loads(output.getvalue());result['capture']=capture
            if code!=0:raise s.CheckBlocked('HISTORY_CAPTURE_BLOCKED',{'safe_reason':capture.get('reason','UNKNOWN')})
        else:result['existing_snapshot_reused']=True
        proofFile=work/'load-proof-private.json'
        if proofFile.is_symlink() or not proofFile.is_file() or proofFile.stat().st_mode&0o077:raise s.CheckBlocked('HISTORY_SOURCE_LOAD_EVIDENCE_REQUIRED')
        proof=json.loads(proofFile.read_text())
        if proof.get('source_load_qualified_for_this_run') is not True or proof.get('readiness_sha256')!=sha:raise s.CheckBlocked('HISTORY_SOURCE_LOAD_NOT_QUALIFIED')
        result['source_load_observation']=proof
        with contextlib.redirect_stdout(io.StringIO()) as output:code=s.main(['--restore-approved',str(work)])
        restore=json.loads(output.getvalue());result['restore']=restore;result['historical_experiment']='PASS' if code==0 else 'BLOCKED'
        result['production_rows_extraction_authorized']=True
        (work/'history-plan-summary.json').write_text(json.dumps(result,indent=2));(work/'history-plan-summary.json').chmod(0o600)
    except Exception as e:result['reason']=e.code if isinstance(e,s.CheckBlocked) else 'HISTORY_PLAN_RUNTIME_FAILURE'
    print(json.dumps(result,indent=2));return 0 if result['historical_experiment']=='PASS' else 78
if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('HISTORY_PLAN_SHA_ARGUMENT_REQUIRED')
    raise SystemExit(execute(sys.argv[1]))
