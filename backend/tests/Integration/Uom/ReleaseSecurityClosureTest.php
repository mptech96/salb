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
}
