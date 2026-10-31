"""Offline private evidence packaging only: no database, subprocess, network or approval recording."""
import hashlib,json,os,pathlib,re,secrets,stat,sys
REASONS={
 'ENV_AND_CONFIG_FILES_DO_NOT_PROVE_EFFECTIVE_RUNTIME':'تصريحات .env وملفات الإعدادات لا تثبت الإعدادات الفعالة في العمليات أو تغييرات Providers.',
 'NO_AUTHENTICATED_PROCESS_TO_MYSQL_PRINCIPAL_LINK':'لا يوجد إثبات مصادقة يربط العملية بحساب MySQL الكامل user@host؛ تشابه الاسم أو UID قرينة فقط.',
 'INDIRECT_SCRIPT_AND_EXTERNAL_WRITER_REVIEW_REQUIRED':'البرامج المتفرعة والكتّاب الخارجيون أو المتوقفون لم تُراجع بصورة مكتملة.',
 'PRIVILEGED_OPERATOR_OWNERSHIP_AND_MAINTENANCE_HANDLING_REQUIRED':'لم يوثق مالك الحسابات الإدارية ومبرر صلاحياتها وطريقة منع كتابتها أثناء الصيانة.',
 'NO_HUMAN_ALLOWLIST_APPROVAL':'لم يصدر قرار المسؤول للقائمة المعتمدة والحسابات والخدمات.'}
class Blocked(Exception):pass
def canonical(value):return (json.dumps(value,ensure_ascii=False,sort_keys=True,indent=2)+'\n').encode('utf-8')
def digest(value):return hashlib.sha256(value).hexdigest()
def packet(profile,source_sha):
 if not re.fullmatch('[a-f0-9]{64}',source_sha):raise Blocked('PROFILE_SHA_REQUIRED')
 if profile.get('format')!='SULB_PRIVATE_WRITER_PROFILE_V1':raise Blocked('PROFILE_FORMAT_REQUIRED')
 linkage=profile.get('writer_linkage',{});accounts=profile.get('database_accounts',[]);rows=linkage.get('accounts_for_explicit_review',[])
 if linkage.get('config_binding',{}).get('status')!='ENV_DECLARATION_ONLY':raise Blocked('REVIEW_SCOPE_REQUIRES_ENV_DECLARATION_PROFILE')
 if not isinstance(accounts,list)or not isinstance(rows,list)or len(accounts)!=9 or len(rows)!=9:raise Blocked('EXACT_NINE_ACCOUNT_REVIEW_REQUIRED')
 principals=[a.get('principal')for a in accounts]
 if any(not isinstance(x,str)or not x or len(x)>512 for x in principals)or len(set(principals))!=9:raise Blocked('UNIQUE_ACCOUNT_IDENTITIES_REQUIRED')
 if sorted(principals)!=sorted(r.get('principal','')for r in rows):raise Blocked('ACCOUNT_REVIEW_MEMBERSHIP_MISMATCH')
 observed={a['principal']:a for a in accounts};draft=[];facts=[]
 for row in rows:
  principal=row['principal'];a=observed[principal]
  for key in ['locked','can_write_sulbprod','global_write_or_admin']:
   if type(a.get(key))is not bool:raise Blocked('ACCOUNT_CAPABILITY_FLAGS_REQUIRED')
  if row.get('approved')is not False or row.get('decision')is not None:raise Blocked('EXISTING_DECISION_REQUIRES_SEPARATE_REVIEW')
  if row.get('locked')!=a['locked']or row.get('potential_source_writer')!=a['can_write_sulbprod']or row.get('privileged')!=a['global_write_or_admin']:raise Blocked('ACCOUNT_FACTS_MISMATCH')
  caps=a.get('capability_evidence',[])
  if not isinstance(caps,list)or len(caps)>256 or any(not isinstance(x,str)or len(x)>2048 for x in caps):raise Blocked('CAPABILITY_EVIDENCE_FORMAT')
  if row.get('observed_capabilities')!=caps:raise Blocked('CAPABILITY_REVIEW_MISMATCH')
  facts.append({'principal':principal,'locked':a['locked'],'source_write_capable':a['can_write_sulbprod'],'privileged':a['global_write_or_admin'],'observed_capabilities':caps,'declared_application_candidate':row.get('declared_application_candidate')is True,'authenticated_process_link_proven':False,'matching_session_ids_are_username_evidence_only':row.get('matching_live_session_ids',[])})
  draft.append({'principal':principal,'owner':None,'service_or_operator_role':None,'decision':None,'capabilities':[{'capability':c,'justification':None,'required_for':None,'decision':None}for c in caps],'maintenance_handling':None,'binding_evidence_sha256':None,'reviewer':None,'review_reference':None,'attested_at_utc':None,'signed_document_sha256':None})
 if (sum(a['can_write_sulbprod']for a in accounts),sum(a['global_write_or_admin']for a in accounts),sum(a['locked']for a in accounts))!=(5,4,4):raise Blocked('REPORTED_ACCOUNT_COUNTS_MISMATCH')
 reasons=linkage.get('missing_proofs',[])
 if len(reasons)!=5 or len(set(reasons))!=5 or set(reasons)!=set(REASONS):raise Blocked('EXPECTED_FIVE_PROOF_CATEGORIES_REQUIRED')
 services=linkage.get('service_schedule_candidates',[])
 if len(services)!=1:raise Blocked('EXACT_ONE_SERVICE_CANDIDATE_REQUIRED')
 for s in services:
  if s.get('approved')is not False:raise Blocked('EXISTING_SERVICE_DECISION_REQUIRES_REVIEW')
  if any(x not in principals for x in s.get('candidate_principals',[])):raise Blocked('UNKNOWN_SERVICE_PRINCIPAL')
 if any(linkage.get(k)is not False for k in ['effective_configuration_verified','external_writers_and_schedules_reviewed','automatically_approved','source_inventory_is_exhaustive_live_proof']):raise Blocked('UNAPPROVED_PROFILE_STATE_REQUIRED')
 facts_doc={'format':'SULB_WRITER_SIGNOFF_EVIDENCE_V1','source_profile_sha256':source_sha,'source_captured_utc':profile.get('captured_utc'),'accounts':facts,'service_candidates':services,'process_unit_observations':linkage.get('process_unit_evidence',[]),'missing_proofs':[{'code':c,'reason_ar':REASONS[c]}for c in reasons],'proved_scope':'CAPABILITIES_AND_OBSERVATIONS_AT_CAPTURE_TIME_ONLY','relations_proven_authenticated':0,'absence_of_process_is_not_absence_of_writer':True,'release_decision':'NO-GO'}
 evidence_sha=digest(canonical(facts_doc))
 unsigned={'format':'SULB_UNSIGNED_WRITER_SIGNOFF_V1','evidence_sha256':evidence_sha,'source_profile_sha256':source_sha,'accounts':draft,'services':[{'path':s.get('path'),'source_sha256':s.get('sha256'),'owner':None,'account_principals':None,'binding_evidence_sha256':None,'indirect_script_review_reference':None,'decision':None,'reviewer':None,'review_reference':None,'attested_at_utc':None,'signed_document_sha256':None}for s in services],'proof_resolutions':[{'code':c,'reviewer':None,'review_reference':None,'evidence_sha256':None,'resolution':None}for c in reasons],'reviewer':None,'role':None,'review_reference':None,'attested_at_utc':None,'signed_document_sha256':None,'approved':False,'production_changes_authorized':False,'deployment_authorized':False}
 summary={'writer_signoff_packet':'PREPARED_UNSIGNED','accounts':9,'source_write_capable':sum(x['source_write_capable']for x in facts),'privileged':sum(x['privileged']for x in facts),'locked':sum(x['locked']for x in facts),'service_candidates':len(services),'missing_proofs':reasons,'authenticated_relations_proven':0,'evidence_sha256':evidence_sha,'source_profile_sha256':source_sha,'approval_recorded':False,'database_connections':0,'live_scan_performed':False,'release_decision':'NO-GO'}
 return facts_doc,unsigned,summary

def private_source(path,sha):
 p=pathlib.Path(path)
 if not re.fullmatch('/home/mgye-api-sulb/sulb-release-review-[a-f0-9]{16}/profile-private.json',str(p))or p.resolve()!=p:raise Blocked('PRIVATE_PROFILE_CANONICAL_PATH_REQUIRED')
 for file,mask in [(p.parent,0o077),(p,0o077)]:
  st=file.lstat()
  if stat.S_ISLNK(st.st_mode)or st.st_uid!=0 or st.st_mode&mask:raise Blocked('PRIVATE_ROOT_OWNERSHIP_REQUIRED')
 if not p.is_file()or p.stat().st_size>1048576:raise Blocked('PROFILE_SIZE_REQUIRED')
 data=p.read_bytes()
 if digest(data)!=sha:raise Blocked('PROFILE_SHA_MISMATCH')
 return json.loads(data)
def write_private(path,data):
 fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
 with os.fdopen(fd,'wb')as f:f.write(data);f.flush();os.fsync(f.fileno())
def private_report(facts):
 def cell(x):return str(x).replace('\n',' ').replace('\r',' ').replace('|','&#124;').replace('<','&lt;').replace('`','&#96;')
 lines=['# حزمة مراجعة كتّاب الإنتاج — خاصة وغير موقعة','','المرصود: قدرات الحسابات وحالتها وقرائن الملفات والعمليات وقت الالتقاط. لا علاقة مصادقة مثبتة بين عملية وحساب. عدم الرصد لا ينفي وجود كاتب.','','| الحساب | كتابة | إداري | مقفل | قرينة التطبيق |','|---|---|---|---|---|']
 for a in facts['accounts']:lines.append('| '+' | '.join(cell(x)for x in [a['principal'],a['source_write_capable'],a['privileged'],a['locked'],a['declared_application_candidate']])+' |')
 lines+=['','## العلاقات المحتملة فقط']
 for s in facts['service_candidates']:lines.append('- '+cell(s.get('path'))+' ← '+', '.join(cell(x)for x in s.get('candidate_principals',[]))+'؛ قرينة موقع وليست إثبات مصادقة.')
 lines+=['','## الأدلة المطلوبة']+[('- '+r['code']+': '+r['reason_ar'])for r in facts['missing_proofs']]
 lines+=['','يملأ المسؤول قرارات الحسابات التسعة ومبرر كل قدرة ومالك الخدمة وأدلة الربط والتاريخ ومرجع القرار والتوقيع داخل الملف غير الموقّع. لا ترفع هذه الملفات إلى GitHub أو المحادثة. لا تشغّل ترحيلًا أو Backfill أو نشرًا.']
 return ('\n'.join(lines)+'\n').encode('utf-8')
def main(argv):
 os.umask(0o077)
 try:
  if sys.platform!='linux'or os.geteuid()!=0 or pathlib.Path.cwd()!=pathlib.Path('/home/sulb-release'):raise Blocked('LINUX_ROOT_RC_CHECKOUT_REQUIRED')
  if len(argv)==1 and argv[0]=='--prepare':
   with open('/dev/tty','r+')as tty:tty.write('Private profile path: ');tty.flush();path=tty.readline().strip();tty.write('Expected profile SHA-256: ');tty.flush();sha=tty.readline().strip()
  elif len(argv)==3 and argv[0]=='--prepare':path,sha=argv[1:]
  else:raise Blocked('EXPLICIT_OFFLINE_PREPARATION_REQUIRED')
  facts,draft,summary=packet(private_source(path,sha),sha)
  out=pathlib.Path('/home/mgye-api-sulb')/('sulb-writer-signoff-'+secrets.token_hex(8));out.mkdir(mode=0o700)
  write_private(out/'evidence-private.json',canonical(facts));write_private(out/'decisions-unsigned.json',canonical(draft));write_private(out/'review-private.md',private_report(facts));summary['private_packet_directory']=str(out);write_private(out/'safe-summary.json',canonical(summary));print(json.dumps(summary,ensure_ascii=True,indent=2));return 0
 except Exception as e:
  reason=str(e)if isinstance(e,Blocked)else'PRIVATE_OFFLINE_PACKET_FAILURE'
  print(json.dumps({'writer_signoff_packet':'BLOCKED','reason':reason,'approval_recorded':False,'database_connections':0,'release_decision':'NO-GO'}));return 78
if __name__=='__main__':raise SystemExit(main(sys.argv[1:]))
