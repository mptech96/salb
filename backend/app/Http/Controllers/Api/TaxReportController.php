<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TaxReportController extends Controller
{
    public function index(Request $r,AccountingContext $ctx)
    {
        $cid=$ctx->companyId($r); $bid=$ctx->branchFilter($r);
        $from=$r->query('from_date',date('Y-m-01')); $to=$r->query('to_date',date('Y-m-t'));

        $sales=$this->invoiceTax('sales',$cid,$bid,$from,$to);
        $purchases=$this->invoiceTax('purchase',$cid,$bid,$from,$to);
        $salesReturns=$this->returnTax('SALES_RETURN',$cid,$bid,$from,$to);
        $purchaseReturns=$this->returnTax('PURCHASE_RETURN',$cid,$bid,$from,$to);

        $grossOutput=round((float)$sales->sum('tax'),3);
        $salesReturnTax=round((float)$salesReturns->sum('tax'),3);
        $grossInput=round((float)$purchases->sum('tax'),3);
        $purchaseReturnTax=round((float)$purchaseReturns->sum('tax'),3);
        $output=round($grossOutput-$salesReturnTax,3);
        $input=round($grossInput-$purchaseReturnTax,3);

        return response()->json(['status'=>true,'data'=>[
            'from_date'=>$from,'to_date'=>$to,
            'sales'=>$sales,'sales_returns'=>$salesReturns,
            'purchases'=>$purchases,'purchase_returns'=>$purchaseReturns,
            'summary'=>[
                'gross_output_tax'=>$grossOutput,'sales_return_tax'=>$salesReturnTax,'output_tax'=>$output,
                'gross_input_tax'=>$grossInput,'purchase_return_tax'=>$purchaseReturnTax,'input_tax'=>$input,
                'net_tax'=>round($output-$input,3),
            ],
            'notice'=>'تقرير ضريبي محاسبي صافي من الفواتير والمردودات المرحلة فقط. التصنيف النهائي لخانات الإقرار الرسمي يعتمد على أكواد الضريبة المهيأة ومتطلبات الجهة الضريبية المعمول بها.',
        ]]);
    }

    private function invoiceTax(string $kind,int $cid,?int $bid,string $from,string $to)
    {
        $sale=$kind==='sales'; $line=$sale?'sales_invoice_lines':'purchase_invoice_lines'; $header=$sale?'sales_invoices':'purchase_invoices'; $fk=$sale?'sales_invoice_id':'purchase_invoice_id';
        return DB::table($line.' as l')->join($header.' as i','i.id','=','l.'.$fk)
            ->where('l.company_id',$cid)->where('i.document_status','POSTED')->whereNull('i.voided_at')->whereBetween('i.invoice_date',[$from,$to])
            ->when($bid!==null,fn($q)=>$q->where('i.branch_id',$bid))
            ->select(DB::raw("COALESCE(l.tax_code_snapshot,'OUT_SCOPE') tax_code"),DB::raw("COALESCE(l.tax_name_snapshot,'خارج النطاق') tax_name"),DB::raw('COALESCE(l.tax_rate_snapshot,l.vat_percent,0) tax_rate'),DB::raw('SUM(COALESCE(l.base_total_before_vat,l.total_before_vat)) taxable'),DB::raw('SUM(COALESCE(l.base_vat_amount,l.vat_amount)) tax'))
            ->groupBy('l.tax_code_snapshot','l.tax_name_snapshot','l.tax_rate_snapshot','l.vat_percent')->get();
    }

    private function returnTax(string $type,int $cid,?int $bid,string $from,string $to)
    {
        return DB::table('commercial_return_lines as l')->join('commercial_returns as r','r.id','=','l.return_id')->leftJoin('tax_codes as t','t.id','=','l.tax_code_id')
            ->where('l.company_id',$cid)->where('r.return_type',$type)->where('r.document_status','POSTED')->whereNull('r.voided_at')->whereBetween('r.return_date',[$from,$to])
            ->when($bid!==null,fn($q)=>$q->where('r.branch_id',$bid))
            ->select(DB::raw("COALESCE(t.tax_code,'OUT_SCOPE') tax_code"),DB::raw("COALESCE(t.tax_name,'خارج النطاق') tax_name"),DB::raw('COALESCE(l.vat_percent,0) tax_rate'),DB::raw('SUM(COALESCE(l.base_total_before_vat,l.total_before_vat)) taxable'),DB::raw('SUM(COALESCE(l.base_vat_amount,l.vat_amount)) tax'))
            ->groupBy('t.tax_code','t.tax_name','l.vat_percent')->get();
    }
}
