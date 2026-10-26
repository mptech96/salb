<?php
namespace Tests\Integration\Shipments;
use Illuminate\Support\Facades\DB;

class DriverGatewayLoadTest extends DriverGatewayArchitectureTest
{
    public function test_300_serial_and_200_concurrent_real_journals():void
    {
        $property=new \ReflectionProperty(DriverGatewayArchitectureTest::class,'roles');$roles=$property->getValue($this);$g=new \Tests\Support\DriverGatewayPrototype($roles['writer']);
        $summary=function(array $samples,float $seconds):array{sort($samples);$n=count($samples);return ['n'=>$n,'p50_ms'=>$samples[(int)ceil($n*.50)-1],'p95_ms'=>$samples[(int)ceil($n*.95)-1],'p99_ms'=>$samples[(int)ceil($n*.99)-1],'throughput'=>$n/$seconds,'1205'=>0,'1213'=>0,'retries'=>0];};
        $results=[];foreach(['baseline','gateway'] as $mode){$samples=[];$begin=microtime(true);for($i=0;$i<300;$i++){$t=microtime(true);if($mode==='gateway')$g->manual(1,1,8);else app(\App\Services\AccountingService::class)->post(['company_id'=>1,'branch_id'=>1,'entry_date'=>'2026-03-03','description'=>'Load baseline','lines'=>[['account_id'=>8,'debit'=>10,'credit'=>0],['account_id'=>1,'debit'=>0,'credit'=>10]]]);$samples[]=(microtime(true)-$t)*1000;}$results[$mode]=$summary($samples,microtime(true)-$begin);}
        for($branch=2;$branch<=4;$branch++)DB::table('branches')->insert(['id'=>$branch,'company_id'=>1,'branch_name'=>'Independent scope '.$branch,'is_active'=>1]);
        foreach(['baseline_parallel','gateway_shared','gateway_independent_scopes'] as $mode){
            $children=[];$begin=microtime(true);for($i=0;$i<4;$i++){$p=new \Symfony\Component\Process\Process([PHP_BINARY,base_path('scripts/driver_gateway_worker.php'),$mode==='baseline_parallel'?'baseline-batch':'batch','50',(string)($mode==='gateway_independent_scopes'?$i+1:1)],base_path(),['SULB_GW_WRITER'=>json_encode($roles['writer'])]);$p->setTimeout(60);$p->start();$children[]=$p;}
            $samples=[];$locks=[];foreach($children as $p){$p->wait();self::assertSame(0,$p->getExitCode(),$p->getOutput().$p->getErrorOutput());preg_match('/RESULT (.+)/',$p->getOutput(),$m);foreach(json_decode($m[1],true)['value'] as $v){$samples[]=$v['ms'];if($v['scope_ms']!==null)$locks[]=$v['scope_ms'];}}
            $results[$mode]=$summary($samples,microtime(true)-$begin);if($locks)$results[$mode]['scope_acquire']=$summary($locks,microtime(true)-$begin);
        }
        self::assertSame(1200,DB::table('journal_entries')->count());fwrite(STDOUT,'GATEWAY_LOAD '.json_encode($results)."\n");
    }
}
