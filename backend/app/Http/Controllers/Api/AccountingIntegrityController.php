<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use App\Services\Accounting\ItemAccountingResolver;
use App\Services\DefaultPartyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountingIntegrityController extends Controller
{
    public function index(Request $r,AccountingContext $ctx,ItemAccountingResolver $resolver,DefaultPartyService $defaults)
    {
        $cid=$ctx->companyId($r); $bid=$ctx->branchFilter($r); $problems=[];
        $defaultSetup=null;
        try{$defaultSetup=$defaults->ensure($cid,$ctx->userId($r));}
        catch(\Throwable $e){$problems[]=['code'=>'DEFAULT_PARTIES','severity'=>'ERROR','message'=>$e->getMessage()];}

        $unresolved=$resolver->unresolved($cid);
        foreach($unresolved as$x)$problems[]=['code'=>'ITEM_ACCOUNTING','severity'=>'ERROR','message'=>($x['item_code']??'').' — '.($x['item_name']??'').' : '.$x['message'],'item_id'=>$x['item_id']??null];

        $negativeLots=[];
        if(Schema::hasTable('inventory_lots')){
            $q=DB::table('inventory_lots as l')->leftJoin('items as i','i.id','=','l.item_id')->where('l.company_id',$cid)->where('l.qty_remaining_kg','<',-0.001);
            if($bid!==null&&Schema::hasColumn('inventory_lots','branch_id'))$q->where('l.branch_id',$bid);
            $negativeLots=$q->select('l.id','l.item_id','l.qty_remaining_kg','i.item_code','i.item_name')->limit(200)->get()->all();
            foreach($negativeLots as$x)$problems[]=['code'=>'NEGATIVE_LOT','severity'=>'ERROR','message'=>'دفعة مخزون سالبة: '.($x->item_code??'').' — '.($x->item_name??'').' / '.number_format((float)$x->qty_remaining_kg,3).' كجم'];
        }

        $postedWithoutJournal=[];
        foreach([['sales_invoices','فاتورة بيع'],['purchase_invoices','فاتورة شراء'],['commercial_returns','مردود']] as[$table,$label]){
            if(!Schema::hasTable($table)||!Schema::hasColumn($table,'journal_entry_id'))continue;
            $q=DB::table($table)->where('company_id',$cid)->where('document_status','POSTED')->whereNull('journal_entry_id');
            if($bid!==null&&Schema::hasColumn($table,'branch_id'))$q->where('branch_id',$bid);
            foreach($q->limit(200)->get(['id',Schema::hasColumn($table,$table==='commercial_returns'?'return_number':'invoice_number')?($table==='commercial_returns'?'return_number':'invoice_number'):'id']) as$x){
                $no=$x->return_number??$x->invoice_number??('#'.$x->id);$postedWithoutJournal[]=['table'=>$table,'id'=>$x->id,'number'=>$no];$problems[]=['code'=>'POSTED_WITHOUT_JOURNAL','severity'=>'ERROR','message'=>$label.' '.$no.' مرحلة بلا قيد مرتبط.'];
            }
        }

        $unlinkedCards=0;
        if(Schema::hasTable('weighbridge_cards')){
            $q=DB::table('weighbridge_cards')->where('company_id',$cid)->whereNull('shipment_id');if($bid!==null)$q->where('branch_id',$bid);$unlinkedCards=$q->count();
        }
        $readyWithOpenCards=0;
        if(Schema::hasTable('shipments')&&Schema::hasTable('weighbridge_cards')){
            $q=DB::table('shipments as s')->join('weighbridge_cards as w','w.shipment_id','=','s.id')->where('s.company_id',$cid)->where('s.commercial_status','READY')->where('w.status','OPEN');if($bid!==null)$q->where('s.branch_id',$bid);$readyWithOpenCards=$q->distinct()->count('s.id');
            if($readyWithOpenCards)$problems[]=['code'=>'READY_OPEN_SCALE','severity'=>'ERROR','message'=>'توجد شحنة READY مرتبطة بكرت ميزان ما يزال مفتوحًا.'];
        }

        return response()->json(['status'=>true,'data'=>[
            'ready'=>count(array_filter($problems,fn($x)=>$x['severity']==='ERROR'))===0,
            'problems'=>$problems,'unresolved_items'=>$unresolved,'negative_lots'=>$negativeLots,'posted_without_journal'=>$postedWithoutJournal,
            'unlinked_weighbridge_cards'=>$unlinkedCards,'ready_shipments_with_open_cards'=>$readyWithOpenCards,
            'default_parties'=>$defaultSetup,
            'rule'=>'لا تعتبر الشركة جاهزة للترحيل الإنتاجي إذا ظهر أي ERROR. كروت الميزان غير المربوطة مسموحة تشغيليًا ولا تُعد خطأ لأنها قد تسبق الشحنة.',
        ]]);
    }
}
