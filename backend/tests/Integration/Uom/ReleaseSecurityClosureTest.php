<?php
namespace Tests\Integration\Uom;
use Illuminate\Support\Facades\DB;
final class ReleaseSecurityClosureTest extends \Tests\TestCase
{
    protected function setUp():void {parent::setUp();require_once __DIR__.'/../../Support/OperationalUomFixture.php';\Tests\Support\OperationalUomFixture::install();}
    protected function tearDown():void {DB::disconnect();parent::tearDown();gc_collect_cycles();}
    public function test_manual_journal_reversal_cannot_commit_twice_under_concurrent_requests():void
    {
        $id=app(\App\Domain\Accounting\Services\JournalService::class)->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-10-26','source_type'=>'MANUAL','description'=>'QA manual financial race','created_by'=>1,'lines'=>[['account_id'=>11,'debit'=>'5','credit'=>0],['account_id'=>12,'debit'=>0,'credit'=>'5']]]);
        require_once __DIR__.'/../../Support/BaseAdapterRace.php';$race=\Tests\Support\BaseAdapterRace::run('MANUAL_REVERSAL_PROBE','journal_entries',$id);self::assertCount(2,$race['waiting_threads']);self::assertSame($race['results'][0],$race['results'][1]);self::assertSame(1,DB::table('journal_entries')->where('reversal_of_id',$id)->count());
    }
    public function test_manual_reversal_is_company_branch_scoped_and_system_sources_remain_with_their_owner():void
    {
        $service=app(\App\Domain\Accounting\Services\JournalService::class);$id=$service->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-10-26','source_type'=>'MANUAL','description'=>'QA manual scope','lines'=>[['account_id'=>11,'debit'=>5,'credit'=>0],['account_id'=>12,'debit'=>0,'credit'=>5]]]);
        foreach([[2,2],[1,2]] as [$company,$branch]){try{$service->reverseManual($company,$id,['entry_date'=>'2026-10-26','reason'=>'QA denied scope'], $branch);self::fail('Foreign reversal accepted');}catch(\RuntimeException $e){self::assertSame('MANUAL_REVERSAL_SOURCE_REQUIRED',$e->getMessage());}self::assertSame(0,DB::table('journal_entries')->where('reversal_of_id',$id)->count());}
        DB::table('journal_entries')->where('id',$id)->update(['source_type'=>'UOM_INVENTORY_PROCESS']);try{$service->reverseManual(1,$id,['entry_date'=>'2026-10-26','reason'=>'QA deny system bypass'],1);self::fail('Operational GL detached from LOT');}catch(\RuntimeException $e){self::assertSame('MANUAL_REVERSAL_SOURCE_REQUIRED',$e->getMessage());}
    }
    public function test_manual_permission_registration_is_restartable_and_grants_no_roles():void
    {
        $before=DB::table('role_permissions')->count();$migration=require base_path('database/uom-migrations/2026_10_26_000013_manual_journal_permissions.php');$migration->up();$migration->up();self::assertSame(2,DB::table('permissions')->whereIn('permission_code',['journals.post','journals.reverse'])->count());self::assertSame($before,DB::table('role_permissions')->count());
    }
    public function test_http_manual_journal_viewer_denial_explicit_authorization_and_idor():void
    {
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();(require base_path('database/uom-migrations/2026_10_26_000013_manual_journal_permissions.php'))->up();$view=DB::table('permissions')->insertGetId(['permission_code'=>'statements.view','permission_name'=>'QA view','permission_scope'=>'COMPANY']);DB::table('roles')->insert(['id'=>3,'role_code'=>'VIEWER','role_name'=>'QA viewer']);DB::table('users')->insert(['id'=>3,'company_id'=>1,'branch_id'=>1,'name'=>'QA viewer','username'=>'qa-journal-viewer','password'=>'synthetic']);DB::table('user_roles')->insert(['user_id'=>3,'company_id'=>1,'role_id'=>3]);DB::table('role_permissions')->insert(['role_id'=>3,'permission_id'=>$view,'company_id'=>1]);$this->actingAs(\App\Models\User::findOrFail(3),'sanctum');
        $body=['branch_id'=>1,'entry_date'=>'2026-10-26','description'=>'QA explicitly authorized journal','lines'=>[['account_id'=>11,'debit'=>'5','credit'=>0],['account_id'=>12,'debit'=>0,'credit'=>'5']]];$this->getJson('/api/journal-entries')->assertOk();$this->postJson('/api/journal-entries',$body)->assertForbidden();self::assertSame(0,DB::table('journal_entries')->count());
        foreach(DB::table('permissions')->whereIn('permission_code',['journals.post','journals.reverse'])->pluck('id') as $permission)DB::table('role_permissions')->insert(['role_id'=>3,'permission_id'=>$permission,'company_id'=>1]);$id=$this->postJson('/api/journal-entries',$body)->assertCreated()->json('id');$reverse=['entry_date'=>'2026-10-26','reason'=>'QA HTTP exact manual reversal'];$one=$this->postJson('/api/journal-entries/'.$id.'/reverse',$reverse)->assertOk()->json('reversal_entry_id');self::assertSame($one,$this->postJson('/api/journal-entries/'.$id.'/reverse',$reverse)->assertOk()->json('reversal_entry_id'));self::assertSame(1,DB::table('journal_entries')->where('reversal_of_id',$id)->count());
        $foreign=app(\App\Domain\Accounting\Services\JournalService::class)->post(['company_id'=>2,'branch_id'=>2,'entry_date'=>'2026-10-26','source_type'=>'MANUAL','description'=>'QA foreign journal','lines'=>[['account_id'=>21,'debit'=>5,'credit'=>0],['account_id'=>22,'debit'=>0,'credit'=>5]]]);$this->postJson('/api/journal-entries/'.$foreign.'/reverse',$reverse)->assertNotFound();self::assertSame(0,DB::table('journal_entries')->where('reversal_of_id',$foreign)->count());
    }
    public function test_manual_http_database_failure_rolls_back_and_does_not_disclose_sql():void
    {
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();$this->actingAs(\App\Models\User::findOrFail(1),'sanctum');DB::unprepared("CREATE TRIGGER qa_manual_gl_failure BEFORE INSERT ON journal_entry_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA sensitive SQL failure'");
        $response=$this->postJson('/api/journal-entries',['branch_id'=>1,'entry_date'=>'2026-10-26','description'=>'QA rollback','lines'=>[['account_id'=>11,'debit'=>5,'credit'=>0],['account_id'=>12,'debit'=>0,'credit'=>5]]])->assertStatus(422);self::assertStringNotContainsString('SQLSTATE',$response->getContent());self::assertStringNotContainsString(DB::connection()->getDatabaseName(),$response->getContent());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('journal_entry_lines')->count());
    }
    public function test_worker_http_database_failure_never_exposes_sql_bindings_or_database_name():void
    {
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();$this->actingAs(\App\Models\User::findOrFail(1),'sanctum');
        DB::unprepared("CREATE TRIGGER qa_worker_private_failure BEFORE INSERT ON workers FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA_PRIVATE_BINDING'");
        $response=$this->postJson('/api/workers',['branch_id'=>1,'worker_name'=>'QA_PRIVATE_BINDING','salary_type'=>'MONTHLY','salary_rate'=>'100.000'])->assertStatus(500);
        foreach(['SQLSTATE','QA_PRIVATE_BINDING',DB::connection()->getDatabaseName(),'insert into']as$private)self::assertStringNotContainsString($private,$response->getContent());
        self::assertSame(0,DB::table('workers')->count());
    }
    private function branchPayrollActor():void
    {
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();
        DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>'payroll','is_enabled'=>1]);
        DB::table('branches')->insert(['id'=>3,'company_id'=>1,'branch_name'=>'QA other branch']);
        DB::table('roles')->insert(['id'=>3,'role_code'=>'QA_PAYROLL_BRANCH','role_name'=>'QA branch payroll']);
        DB::table('users')->insert(['id'=>3,'company_id'=>1,'branch_id'=>1,'name'=>'QA branch actor','username'=>'qa-payroll-branch','password'=>'synthetic']);
        DB::table('user_roles')->insert(['user_id'=>3,'company_id'=>1,'role_id'=>3]);
        foreach(['workers.view','payroll.manage']as$code){$id=DB::table('permissions')->insertGetId(['permission_code'=>$code,'permission_name'=>$code,'permission_scope'=>'COMPANY']);DB::table('role_permissions')->insert(['company_id'=>1,'role_id'=>3,'permission_id'=>$id]);}
        foreach([[1,1,'100.000'],[2,3,'200.000']]as[$id,$branch,$rate])DB::table('workers')->insert(['id'=>$id,'company_id'=>1,'branch_id'=>$branch,'worker_name'=>'QA PRIVATE WORKER '.$id,'salary_type'=>'MONTHLY','salary_rate'=>$rate,'worker_status'=>'ACTIVE','is_active'=>1,'iban'=>'QA_PRIVATE_IBAN_'.$id]);
        $this->actingAs(\App\Models\User::findOrFail(3),'sanctum');
    }
    public function test_payroll_generation_does_not_copy_another_branch_worker_into_local_salary_run():void
    {
        $this->branchPayrollActor();$response=$this->postJson('/api/payroll/generate',['salary_month'=>'2026-10-01','branch_id'=>1]);self::assertSame(201,$response->getStatusCode(),$response->getContent());
        $id=$response->json('data.salary_run_id');self::assertSame([1],DB::table('worker_salary_lines')->where('salary_run_id',$id)->pluck('worker_id')->map(fn($id)=>(int)$id)->all());self::assertSame('100.000',DB::table('worker_salary_runs')->where('id',$id)->value('total_amount'));
    }
    public function test_payroll_list_and_salary_slip_do_not_disclose_another_branch_personal_data():void
    {
        $this->branchPayrollActor();DB::table('worker_salary_runs')->insert(['id'=>21,'company_id'=>1,'branch_id'=>3,'salary_month'=>'2026-11-01','run_number'=>'QA_PRIVATE_OTHER_BRANCH','status'=>'DRAFT','total_amount'=>'200.000']);
        DB::table('worker_salary_lines')->insert(['company_id'=>1,'salary_run_id'=>21,'worker_id'=>2,'salary_type'=>'MONTHLY','rate_amount'=>'200.000','net_salary'=>'200.000']);
        $list=$this->getJson('/api/payroll');self::assertSame(200,$list->getStatusCode(),$list->getContent());self::assertStringNotContainsString('QA_PRIVATE_OTHER_BRANCH',$list->getContent());
        $slip=$this->getJson('/api/payroll/21/salary-slip/2')->assertNotFound();self::assertStringNotContainsString('QA_PRIVATE_IBAN_2',$slip->getContent());
        $this->getJson('/api/payroll/21')->assertNotFound();
    }
    public function test_historical_mixed_branch_salary_run_is_not_silently_recomputed_or_disclosed():void
    {
        $this->branchPayrollActor();DB::table('worker_salary_runs')->insert(['id'=>31,'company_id'=>1,'branch_id'=>1,'salary_month'=>'2026-12-01','run_number'=>'QA_OLD_MIXED','status'=>'DRAFT','total_amount'=>'300.000']);
        foreach([1,2]as$worker)DB::table('worker_salary_lines')->insert(['company_id'=>1,'salary_run_id'=>31,'worker_id'=>$worker,'salary_type'=>'MONTHLY','rate_amount'=>$worker===1?'100.000':'200.000','net_salary'=>$worker===1?'100.000':'200.000']);
        $before=serialize([DB::table('worker_salary_runs')->get(),DB::table('worker_salary_lines')->get()]);$response=$this->getJson('/api/payroll/31')->assertStatus(422);
        foreach(['QA PRIVATE WORKER','QA_PRIVATE_IBAN']as$private)self::assertStringNotContainsString($private,$response->getContent());
        $this->getJson('/api/payroll/31/salary-slip/2')->assertNotFound();$this->getJson('/api/payroll/31/salary-slip/1')->assertOk();
        self::assertSame($before,serialize([DB::table('worker_salary_runs')->get(),DB::table('worker_salary_lines')->get()]));
    }
    public function test_payroll_generate_sql_failure_is_sanitized_and_leaves_no_partial_run():void
    {
        $this->branchPayrollActor();DB::unprepared("CREATE TRIGGER qa_payroll_private_failure BEFORE INSERT ON worker_salary_runs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA_PRIVATE_PAYROLL'");
        $response=$this->postJson('/api/payroll/generate',['salary_month'=>'2026-10-01','branch_id'=>1])->assertStatus(422);
        foreach(['SQLSTATE','QA_PRIVATE_PAYROLL',DB::connection()->getDatabaseName()]as$private)self::assertStringNotContainsString($private,$response->getContent());
        self::assertSame(0,DB::table('worker_salary_runs')->count());self::assertSame(0,DB::table('worker_salary_lines')->count());
    }
    public function test_shipment_cost_draft_insert_update_delete_sql_failures_are_private_and_atomic():void
    {
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();$this->actingAs(\App\Models\User::findOrFail(1),'sanctum');
        DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>'shipments','is_enabled'=>1]);
        $shipment=DB::table('shipments')->insertGetId(['company_id'=>1,'branch_id'=>1,'shipment_number'=>'QA_PRIVATE_COST','shipment_type'=>'PURCHASE','shipment_date'=>'2026-10-27','supplier_id'=>1,'commercial_status'=>'DRAFT']);
        DB::table('expense_types')->insert(['id'=>1,'company_id'=>1,'type_name'=>'QA freight','type_code'=>'QA-FREIGHT','usage_type'=>'SHIPMENT','affects_cost'=>1,'account_id'=>11]);
        $body=['shipment_id'=>$shipment,'expense_type_id'=>1,'expense_date'=>'2026-10-27','amount'=>'10.001','currency_code'=>'SAR','payment_status'=>'UNPAID','capitalizable'=>true,'notes'=>'QA_PRIVATE_COST'];
        foreach(['INSERT','UPDATE','DELETE']as$action){
            DB::unprepared("CREATE TRIGGER qa_cost_private_failure BEFORE $action ON shipment_costs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA_PRIVATE_COST'");
            if($action==='INSERT')$response=$this->postJson('/api/shipment-costs',$body);
            elseif($action==='UPDATE')$response=$this->putJson('/api/shipment-costs/'.$id,array_replace($body,['amount'=>'20.001']));
            else $response=$this->deleteJson('/api/shipment-costs/'.$id);
            $response->assertStatus(422);foreach(['SQLSTATE','QA_PRIVATE_COST',DB::connection()->getDatabaseName()]as$private)self::assertStringNotContainsString($private,$response->getContent());
            DB::unprepared('DROP TRIGGER qa_cost_private_failure');
            if($action==='INSERT'){self::assertSame(0,DB::table('shipment_costs')->count());$id=$this->postJson('/api/shipment-costs',$body)->assertCreated()->json('id');}
            else{self::assertSame(1,DB::table('shipment_costs')->count());self::assertSame('10.001',DB::table('shipment_costs')->where('id',$id)->value('amount'));}
        }
        self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::transactionLevel());
    }
    public function test_inventory_draft_delete_sql_failure_rolls_back_children_and_hides_bindings():void
    {
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();$this->actingAs(\App\Models\User::findOrFail(1),'sanctum');
        DB::table('plan_features')->insert(['plan_id'=>99,'feature_code'=>'processing','is_enabled'=>1]);
        $id=DB::table('inventory_operations')->insertGetId(['company_id'=>1,'branch_id'=>1,'from_branch_id'=>1,'to_branch_id'=>1,'operation_number'=>'QA_PRIVATE_OPERATION','operation_type'=>'SORTING','operation_date'=>'2026-10-27','status'=>'DRAFT']);
        DB::table('inventory_operation_lines')->insert(['company_id'=>1,'branch_id'=>1,'operation_id'=>$id,'line_type'=>'FROM','item_id'=>2,'qty'=>'1.000','qty_kg'=>'1.000','total_cost'=>'10.000']);
        $before=serialize([DB::table('inventory_operations')->get(),DB::table('inventory_operation_lines')->get()]);DB::unprepared("CREATE TRIGGER qa_operation_private_failure BEFORE DELETE ON inventory_operations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA_PRIVATE_OPERATION'");
        $response=$this->deleteJson('/api/inventory-operations/'.$id)->assertStatus(422);foreach(['SQLSTATE','QA_PRIVATE_OPERATION',DB::connection()->getDatabaseName()]as$private)self::assertStringNotContainsString($private,$response->getContent());self::assertSame($before,serialize([DB::table('inventory_operations')->get(),DB::table('inventory_operation_lines')->get()]));self::assertSame(0,DB::transactionLevel());
    }
    public function test_account_and_treasury_database_failures_do_not_disclose_private_bindings():void
    {
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();$this->actingAs(\App\Models\User::findOrFail(1),'sanctum');$before=DB::table('accounts')->count();
        foreach([
            ['accounts','/api/accounts',['account_code'=>'QA_PRIVATE_ACCOUNT','account_name'=>'QA private','account_type'=>'ASSET','normal_side'=>'DEBIT']],
            ['financial_accounts','/api/financial-accounts',['branch_id'=>1,'account_code'=>'QA_PRIVATE_ACCOUNT','account_name'=>'QA private','account_type'=>'BANK','gl_account_id'=>11,'currency_code'=>'SAR','iban'=>'QA_PRIVATE_IBAN']]
        ]as[$table,$url,$body]){
            DB::unprepared("CREATE TRIGGER qa_account_private_failure BEFORE INSERT ON $table FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA_PRIVATE_ACCOUNT'");$response=$this->postJson($url,$body)->assertStatus(422);self::assertStringContainsString('تعذرت عملية قاعدة البيانات.',$response->json('message'));
            foreach(['SQLSTATE','QA_PRIVATE_ACCOUNT','QA_PRIVATE_IBAN',DB::connection()->getDatabaseName()]as$private)self::assertStringNotContainsString($private,$response->getContent());DB::unprepared('DROP TRIGGER qa_account_private_failure');
        }
        self::assertSame($before,DB::table('accounts')->count());self::assertSame(0,DB::table('financial_accounts')->count());
    }
    public function test_voucher_late_gl_failure_rolls_back_finance_and_redacts_string_wrapped_sql():void
    {
        require_once __DIR__.'/../../Support/UomSupportActor.php';\Tests\Support\UomSupportActor::install();$this->actingAs(\App\Models\User::findOrFail(1),'sanctum');
        foreach([[14,'BANK','ASSET'],[15,'EXP','EXPENSE']]as[$id,$code,$type])DB::table('accounts')->insert(['id'=>$id,'company_id'=>1,'account_code'=>$code,'account_name'=>'QA '.$code,'account_type'=>$type,'normal_side'=>'DEBIT','allow_posting'=>1,'allow_cost_center'=>0]);
        DB::table('financial_accounts')->insert(['id'=>1,'company_id'=>1,'branch_id'=>1,'account_name'=>'QA bank','account_code'=>'QA-BANK','account_type'=>'BANK','currency_code'=>'SAR','gl_account_id'=>14]);DB::table('voucher_types')->insert(['id'=>2,'type_code'=>'PAYMENT','type_name'=>'Payment']);$before=serialize(DB::table('sulb_document_sequences')->get());
        DB::unprepared("CREATE TRIGGER qa_voucher_private_failure BEFORE INSERT ON journal_entry_lines FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA_PRIVATE_VOUCHER'");
        $response=$this->postJson('/api/vouchers',['branch_id'=>1,'voucher_type_id'=>2,'voucher_date'=>'2026-10-27','reference_type'=>'ACCOUNT','reference_id'=>15,'amount'=>'10.001','payment_method'=>'BANK','financial_account_id'=>1,'currency_code'=>'SAR','notes'=>'QA_PRIVATE_VOUCHER'])->assertStatus(422);
        self::assertStringContainsString('تعذرت عملية قاعدة البيانات.',$response->json('message'));foreach(['SQLSTATE','QA_PRIVATE_VOUCHER',DB::connection()->getDatabaseName()]as$private)self::assertStringNotContainsString($private,$response->getContent());
        self::assertSame(0,DB::table('vouchers')->count());self::assertSame(0,DB::table('journal_entries')->count());self::assertSame(0,DB::table('journal_entry_lines')->count());self::assertSame($before,serialize(DB::table('sulb_document_sequences')->get()));self::assertSame(0,DB::transactionLevel());
    }
}
