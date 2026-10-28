"""Build explicit production adapters. Original QA files/guards remain byte-for-byte untouched."""
import pathlib,json,hashlib,re
root=pathlib.Path(__file__).resolve().parents[2]
c=json.loads((root/'ops/sulb-rc/migration-catalog.json').read_text());u=c['uom_order'];p={m['path']:m for m in c['proposed']};schedule=[]
for i,m in enumerate(u):
 if i==9:
  for n in ['stock_movements_company_source_index.php','shipment_cost_parent_fk.php','shipment_cost_allocation_receipts.php','inventory_lot_value_balances.php']:schedule.append(p['docs/erp-baseline/proposed-migrations/'+n])
 schedule.append(m)
assert len(schedule)==18
out=root/'ops/sulb-rc/production-upgrade';out.mkdir(exist_ok=True);manifest=[]
for i,m in enumerate(schedule,1):
 source=(root/m['path']).read_text();assert hashlib.sha256(source.encode()).hexdigest()==m['sha256']
 code=re.sub(r"require_once\s+__DIR__\s*\.\s*'/../../tests/Support/UomStageAProof.php'\s*;",'',source)
 code=code.replace('\\Tests\\Support\\UomStageAProof','\\Sulb\\Release\\ProductionUpgradeContext').replace('\\Tests\\Support\\QaEngineSchema','\\Sulb\\Release\\ProductionUpgradeDialect')
 assert 'Tests\\Support' not in code
 code=code.replace('__DIR__',"(SULB_UPGRADE_ROOT.'/"+str(pathlib.PurePosixPath(m['path']).parent)+"')")
 code,n=re.subn(r'(public function up\(\)\s*:\s*void\s*\{)',r'\1\n        \\Sulb\\Release\\ProductionUpgradeContext::guard();',code);assert n==1
 code,n=re.subn(r'(public function down\(\)\s*:\s*void\s*\{)',r"\1\n        throw new \\RuntimeException('PRODUCTION_DESTRUCTIVE_DOWN_FORBIDDEN');",code);assert n==1
 name=f'{i:03}.php';(out/name).write_text(code)
 manifest.append({'step':i,'source_path':m['path'],'source_sha256':m['sha256'],'adapter_path':'ops/sulb-rc/production-upgrade/'+name,'adapter_sha256':hashlib.sha256(code.encode()).hexdigest(),'repository_name':pathlib.Path(m['path']).stem})
(root/'ops/sulb-rc/production-upgrade/catalog.json').write_text(json.dumps({'format':'EXPLICIT_PRODUCTION_ADAPTERS_V1','qa_guard_untouched':True,'production_qualified':False,'schedule':manifest},indent=2)+'\n')
print('BUILT 18 explicit guarded adapters; original QA sources untouched; production_qualified=false')
