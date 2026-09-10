<?php

namespace App\Services\Demo;

use App\Domain\Accounting\Services\AccountingReportService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class DemoIntegrityService
{
    public function __construct(private AccountingReportService $reports) {}

    public function validate(int $companyId, int $financialYearId): array
    {
        $year=DB::table('financial_years')->where('company_id',$companyId)->where('id',$financialYearId)->first();
        if(!$year)throw new RuntimeException('Demo financial year is missing.');
        $unbalanced=DB::table('journal_entries as e')->join('journal_entry_lines as l','l.journal_entry_id','=','e.id')->where('e.company_id',$companyId)->where('e.status','POSTED')->groupBy('e.id')->havingRaw('ABS(SUM(l.debit)-SUM(l.credit)) > 0.0001')->get()->count();
        $duplicates=DB::table('journal_entries')->where('company_id',$companyId)->where('status','POSTED')->whereNotNull('source_type')->whereNotNull('source_id')->groupBy('source_type','source_id')->havingRaw('COUNT(*) > 1')->get()->count();
        $negativeLots=DB::table('inventory_lots')->where('company_id',$companyId)->where('qty_remaining_kg','<',-0.0001)->count();
        $foreignLines=DB::table('journal_entry_lines as l')->join('journal_entries as e','e.id','=','l.journal_entry_id')->where('e.company_id',$companyId)->where('l.company_id','<>',$companyId)->count();
        $lot=DB::table('inventory_lots')->where('company_id',$companyId)->selectRaw('COALESCE(SUM(qty_received_kg),0) received,COALESCE(SUM(qty_remaining_kg),0) remaining,COALESCE(SUM(qty_sold_kg),0) sold')->first();
        $movement=DB::table('stock_movements')->where('company_id',$companyId)->selectRaw("COALESCE(SUM(CASE WHEN movement_type='IN' THEN qty_kg ELSE -qty_kg END),0) balance")->value('balance');
        $fifo=(float)DB::table('sales_line_lot_sources')->where('company_id',$companyId)->sum('qty_kg');
        $saleQty=(float)DB::table('sales_invoice_lines as l')->join('sales_invoices as i','i.id','=','l.sales_invoice_id')->where('i.company_id',$companyId)->where('i.document_status','POSTED')->sum('l.qty_kg');
        $customerGl=DB::table('journal_entry_lines')->where('company_id',$companyId)->where('party_type','CUSTOMER')->selectRaw('COALESCE(SUM(debit-credit),0) balance')->value('balance');
        $customerExpected=(float)DB::table('sales_invoices')->where('company_id',$companyId)->where('document_status','POSTED')->sum('base_total_amount')-(float)DB::table('vouchers')->where('company_id',$companyId)->where('reference_type','CUSTOMER')->sum('amount');
        $supplierGl=DB::table('journal_entry_lines')->where('company_id',$companyId)->where('party_type','SUPPLIER')->selectRaw('COALESCE(SUM(credit-debit),0) balance')->value('balance');
        $supplierExpected=(float)DB::table('purchase_invoices')->where('company_id',$companyId)->where('document_status','POSTED')->sum('base_total_amount')-(float)DB::table('vouchers')->where('company_id',$companyId)->where('reference_type','SUPPLIER')->sum('amount');
        $trial=$this->reports->trialBalance($companyId,null,['financial_year_id'=>$financialYearId,'from_date'=>$year->start_date,'to_date'=>$year->end_date]);
        $income=$this->reports->incomeStatement($companyId,null,['financial_year_id'=>$financialYearId,'from_date'=>$year->start_date,'to_date'=>$year->end_date]);
        $balance=$this->reports->balanceSheet($companyId,null,['financial_year_id'=>$financialYearId,'as_of'=>$year->end_date]);
        $result=['unbalanced_journals'=>$unbalanced,'duplicate_source_journals'=>$duplicates,'negative_lots'=>$negativeLots,'cross_company_journal_lines'=>$foreignLines,'inventory_lot_balance'=>(float)$lot->remaining,'stock_movement_balance'=>(float)$movement,'fifo_allocated_qty'=>$fifo,'posted_sales_qty'=>$saleQty,'customer_statement_difference'=>round((float)$customerGl-$customerExpected,3),'supplier_statement_difference'=>round((float)$supplierGl-$supplierExpected,3),'trial_balance_difference'=>(float)$trial['totals']['difference'],'balance_sheet_difference'=>(float)$balance['difference'],'net_result'=>(float)$income['net_result']];
        $inventoryDifference=abs(((float)$lot->received-(float)$lot->sold)-(float)$lot->remaining);
        if($unbalanced||$duplicates||$negativeLots||$foreignLines||$inventoryDifference>.001||abs((float)$movement-(float)$lot->remaining)>.001||abs($fifo-$saleQty)>.001||abs($result['customer_statement_difference'])>.001||abs($result['supplier_statement_difference'])>.001||abs($result['trial_balance_difference'])>.0001||abs($result['balance_sheet_difference'])>.0001)throw new RuntimeException('Demo accounting integrity failed: '.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        return [...$result,'status'=>'PASS'];
    }
}
