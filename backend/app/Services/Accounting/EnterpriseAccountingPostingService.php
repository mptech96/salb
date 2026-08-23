<?php

namespace App\Services\Accounting;

use App\Domain\Accounting\Services\JournalService;
use App\Services\TaxEngineService;
use Illuminate\Support\Facades\DB;

/**
 * The commercial posting layer used by Stage 7.
 * Inventory physical movements are performed by EnterpriseInvoiceService first;
 * this service then posts GL/subledger using item/category/group account mapping.
 */
class EnterpriseAccountingPostingService
{
    public function __construct(
        private JournalService $journals,
        private ItemAccountingResolver $accounts,
        private TaxEngineService $taxes,
    ) {}

    public function postSale(int $companyId,int $invoiceId,int $userId): int
    {
        $inv=DB::table('sales_invoices')->where('company_id',$companyId)->where('id',$invoiceId)->first();
        if(!$inv)throw new \RuntimeException('فاتورة البيع غير موجودة.');
        if($inv->journal_entry_id)return (int)$inv->journal_entry_id;
        $lines=DB::table('sales_invoice_lines')->where('company_id',$companyId)->where('sales_invoice_id',$invoiceId)->orderBy('id')->get();
        if($lines->isEmpty())throw new \RuntimeException('فاتورة البيع لا تحتوي أصنافاً.');

        $journal=[];$revenueTotal=0.0;
        foreach($lines as$line){
            $base=round((float)($line->base_total_before_vat??$line->total_before_vat),3);if(abs($base)<0.0001)continue;
            $acc=$this->accounts->sales($companyId,(int)$line->item_id);
            $this->pushGrouped($journal,$acc,0,$base,'إيراد بيع - '.$this->itemName((int)$line->item_id));$revenueTotal+=$base;
        }

        $vatTotal=round((float)($inv->base_vat_amount??$inv->vat_amount),3);$postedTax=0.0;
        $taxRows=DB::table('sales_invoice_lines')->where('company_id',$companyId)->where('sales_invoice_id',$invoiceId)
            ->select('tax_code_id',DB::raw('SUM(COALESCE(base_vat_amount,vat_amount)) tax'))->groupBy('tax_code_id')->get();
        foreach($taxRows as$taxRow){
            $amount=round((float)$taxRow->tax,3);if($amount<=0)continue;
            $acc=$this->taxes->taxAccount($companyId,$taxRow->tax_code_id?(int)$taxRow->tax_code_id:null,'SALES');
            $this->pushGrouped($journal,$acc,0,$amount,'ضريبة مخرجات');$postedTax+=$amount;
        }
        $taxDiff=round($vatTotal-$postedTax,3);
        if(abs($taxDiff)>0.0001){$acc=$this->accounts->settingAny($companyId,['VAT_OUTPUT_ACCOUNT']);$this->pushGrouped($journal,$acc,$taxDiff<0?abs($taxDiff):0,$taxDiff>0?$taxDiff:0,'تسوية تقريب ضريبة المخرجات');}

        $total=round((float)($inv->base_total_amount??$inv->total_amount),3);
        $ar=$this->accounts->receivable($companyId,(int)$inv->customer_id);
        $journal[]=['account_id'=>$ar,'debit'=>$total,'credit'=>0,'party_type'=>'CUSTOMER','party_id'=>(int)$inv->customer_id,'description'=>'ذمة العميل - فاتورة '.$inv->invoice_number];

        // COGS is determined from the actual FIFO lot sources created immediately before this call.
        foreach($lines as$line){
            if((int)($line->track_inventory_snapshot??1)!==1||strtoupper((string)($line->item_type_snapshot??'STOCK'))==='SERVICE')continue;
            $cost=round((float)DB::table('sales_line_lot_sources')->where('company_id',$companyId)->where('sales_invoice_line_id',$line->id)->sum('total_cost'),3);
            if($cost<=0)continue;
            $cogs=$this->accounts->cogs($companyId,(int)$line->item_id);$inventory=$this->accounts->inventory($companyId,(int)$line->item_id);
            $this->pushGrouped($journal,$cogs,$cost,0,'تكلفة مبيعات - '.$this->itemName((int)$line->item_id));
            $this->pushGrouped($journal,$inventory,0,$cost,'إخراج مخزون مباع - '.$this->itemName((int)$line->item_id));
        }

        $this->assertBalanced($journal,'فاتورة البيع '.$inv->invoice_number);
        $jid=$this->journals->post([
            'company_id'=>$companyId,'branch_id'=>(int)$inv->branch_id,'entry_date'=>$inv->invoice_date,
            'source_type'=>'SALE','source_id'=>$invoiceId,'description'=>'فاتورة بيع '.$inv->invoice_number,
            'currency_code'=>$inv->currency_code??null,'exchange_rate'=>$inv->exchange_rate??null,
            'lines'=>array_values($journal),'is_system_generated'=>1,'created_by'=>$userId,
        ]);
        DB::table('sales_invoices')->where('id',$invoiceId)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);
        DB::table('stock_movements')->where('company_id',$companyId)->where('source_type','SALE')->where('source_id',$invoiceId)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);
        return (int)$jid;
    }

    public function postPurchase(int $companyId,int $invoiceId,int $userId): int
    {
        $inv=DB::table('purchase_invoices')->where('company_id',$companyId)->where('id',$invoiceId)->first();
        if(!$inv)throw new \RuntimeException('فاتورة الشراء غير موجودة.');
        if($inv->journal_entry_id)return (int)$inv->journal_entry_id;
        $lines=DB::table('purchase_invoice_lines')->where('company_id',$companyId)->where('purchase_invoice_id',$invoiceId)->orderBy('id')->get();
        if($lines->isEmpty())throw new \RuntimeException('فاتورة الشراء لا تحتوي أصنافاً.');

        $journal=[];$stockBasis=[];$stockBasisTotal=0.0;$serviceExists=false;
        foreach($lines as$line){
            $base=round((float)($line->base_total_before_vat??$line->total_before_vat),3);if(abs($base)<0.0001)continue;
            $stock=(int)($line->track_inventory_snapshot??1)===1&&strtoupper((string)($line->item_type_snapshot??'STOCK'))!=='SERVICE';
            $acc=$stock?$this->accounts->inventory($companyId,(int)$line->item_id):$this->accounts->purchaseExpense($companyId,(int)$line->item_id);
            $this->pushGrouped($journal,$acc,$base,0,($stock?'إثبات مخزون - ':'شراء خدمة/مصروف - ').$this->itemName((int)$line->item_id));
            if($stock){$stockBasis[(int)$line->item_id]=($stockBasis[(int)$line->item_id]??0)+$base;$stockBasisTotal+=$base;}else$serviceExists=true;
        }

        // Header freight/extra costs are part of invoice total and lot cost in Stage 6.
        $rate=(float)($inv->exchange_rate?:1);$headerCosts=round(((float)($inv->transport_cost??0)+(float)($inv->extra_cost??0))*$rate,3);
        if($headerCosts>0){
            if($stockBasisTotal>0){
                $remain=$headerCosts;$keys=array_keys($stockBasis);$last=end($keys);
                foreach($stockBasis as$itemId=>$basis){$share=$itemId===$last?$remain:round($headerCosts*($basis/$stockBasisTotal),3);$remain=round($remain-$share,3);$this->pushGrouped($journal,$this->accounts->inventory($companyId,$itemId),$share,0,'تكلفة شراء مباشرة محملة على المخزون');}
            }else{
                $acc=$lines->isNotEmpty()?$this->accounts->purchaseExpense($companyId,(int)$lines->first()->item_id):$this->accounts->settingAny($companyId,['GENERAL_EXPENSE_ACCOUNT']);
                $this->pushGrouped($journal,$acc,$headerCosts,0,'تكاليف شراء مباشرة');
            }
        }

        $vatTotal=round((float)($inv->base_vat_amount??$inv->vat_amount),3);$postedTax=0.0;
        $taxRows=DB::table('purchase_invoice_lines')->where('company_id',$companyId)->where('purchase_invoice_id',$invoiceId)
            ->select('tax_code_id',DB::raw('SUM(COALESCE(base_vat_amount,vat_amount)) tax'))->groupBy('tax_code_id')->get();
        foreach($taxRows as$taxRow){$amount=round((float)$taxRow->tax,3);if($amount<=0)continue;$acc=$this->taxes->taxAccount($companyId,$taxRow->tax_code_id?(int)$taxRow->tax_code_id:null,'PURCHASE');$this->pushGrouped($journal,$acc,$amount,0,'ضريبة مدخلات');$postedTax+=$amount;}
        $taxDiff=round($vatTotal-$postedTax,3);if(abs($taxDiff)>0.0001){$acc=$this->accounts->settingAny($companyId,['VAT_INPUT_ACCOUNT']);$this->pushGrouped($journal,$acc,$taxDiff>0?$taxDiff:0,$taxDiff<0?abs($taxDiff):0,'تسوية تقريب ضريبة المدخلات');}

        $total=round((float)($inv->base_total_amount??$inv->total_amount),3);$ap=$this->accounts->payable($companyId,(int)$inv->supplier_id);
        $journal[]=['account_id'=>$ap,'debit'=>0,'credit'=>$total,'party_type'=>'SUPPLIER','party_id'=>(int)$inv->supplier_id,'description'=>'ذمة المورد - فاتورة '.$inv->invoice_number];

        $this->assertBalanced($journal,'فاتورة الشراء '.$inv->invoice_number);
        $jid=$this->journals->post([
            'company_id'=>$companyId,'branch_id'=>(int)$inv->branch_id,'entry_date'=>$inv->invoice_date,
            'source_type'=>'PURCHASE','source_id'=>$invoiceId,'description'=>'فاتورة شراء '.$inv->invoice_number,
            'currency_code'=>$inv->currency_code??null,'exchange_rate'=>$inv->exchange_rate??null,
            'lines'=>array_values($journal),'is_system_generated'=>1,'created_by'=>$userId,
        ]);
        DB::table('purchase_invoices')->where('id',$invoiceId)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);
        DB::table('stock_movements')->where('company_id',$companyId)->where('source_type','PURCHASE')->where('source_id',$invoiceId)->update(['journal_entry_id'=>$jid,'updated_at'=>now()]);
        return (int)$jid;
    }

    private function pushGrouped(array &$lines,int $account,float $debit,float $credit,string $description): void
    {
        $key=$account.'|'.($debit>0?'D':'C');
        if(!isset($lines[$key]))$lines[$key]=['account_id'=>$account,'debit'=>0.0,'credit'=>0.0,'description'=>$description];
        $lines[$key]['debit']=round((float)$lines[$key]['debit']+$debit,3);$lines[$key]['credit']=round((float)$lines[$key]['credit']+$credit,3);
    }

    private function assertBalanced(array $lines,string $document): void
    {
        $d=round(array_sum(array_map(fn($x)=>(float)($x['debit']??0),$lines)),3);$c=round(array_sum(array_map(fn($x)=>(float)($x['credit']??0),$lines)),3);
        if(abs($d-$c)>0.01)throw new \RuntimeException('القيد غير متوازن لـ '.$document.'؛ مدين '.number_format($d,3).' / دائن '.number_format($c,3).'. تم إلغاء العملية بالكامل.');
    }

    private function itemName(int $itemId): string
    { return (string)(DB::table('items')->where('id',$itemId)->value('item_name')?:('#'.$itemId)); }
}
