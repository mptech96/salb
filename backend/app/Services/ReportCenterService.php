<?php

namespace App\Services;

use App\Domain\Accounting\Services\AccountingReportService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportCenterService
{
    public function __construct(private AccountingReportService $accounting) {}
    public function catalog(): array
    {
        return [
            ['key' => 'executive', 'label' => 'الملخص التنفيذي', 'group' => 'إدارة', 'description' => 'المبيعات والمشتريات والمصروفات والمخزون والربح المحقق.'],
            ['key' => 'sales', 'label' => 'المبيعات', 'group' => 'مالية', 'description' => 'فواتير البيع حسب العميل والفرع والتاريخ.'],
            ['key' => 'purchases', 'label' => 'المشتريات', 'group' => 'مالية', 'description' => 'فواتير الشراء حسب المورد والفرع والتاريخ.'],
            ['key' => 'expenses', 'label' => 'المصروفات', 'group' => 'مالية', 'description' => 'تفاصيل المصروفات وأنواعها وحالة السداد.'],
            ['key' => 'vouchers', 'label' => 'سندات القبض والصرف', 'group' => 'مالية', 'description' => 'السندات المالية حسب النوع والطرف وطريقة الدفع.'],
            ['key' => 'journal-entries', 'label' => 'دفتر اليومية', 'group' => 'محاسبة', 'description' => 'القيود المرحلة ومصدر كل قيد وإجمالي أطرافه.'],
            ['key' => 'trial-balance', 'label' => 'ميزان المراجعة', 'group' => 'محاسبة', 'description' => 'افتتاحي وحركة وختامي لكل حساب مع فحص توازن المدين والدائن.'],
            ['key' => 'income-statement', 'label' => 'قائمة الدخل', 'group' => 'محاسبة', 'description' => 'الإيرادات وتكلفة الإيراد والمصروفات وصافي النتيجة.'],
            ['key' => 'balance-sheet', 'label' => 'قائمة المركز المالي', 'group' => 'محاسبة', 'description' => 'الأصول والالتزامات وحقوق الملكية ونتيجة الفترة.'],
            ['key' => 'inventory', 'label' => 'أرصدة المخزون', 'group' => 'مخزون', 'description' => 'الرصيد بالكيلو والطن وقيمة المخزون ومتوسط التكلفة.'],
            ['key' => 'inventory-lots', 'label' => 'دفعات المخزون', 'group' => 'مخزون', 'description' => 'مصدر كل دفعة وتكلفتها والمتبقي منها.'],
            ['key' => 'inventory-movements', 'label' => 'حركة المخزون', 'group' => 'مخزون', 'description' => 'كل دخول وخروج مع المصدر والتكلفة.'],
            ['key' => 'inventory-operations', 'label' => 'الفرز والتحويل', 'group' => 'مخزون', 'description' => 'عمليات التحويل بين الفروع والمعالجة والفاقد والزيادة.'],
            ['key' => 'shipments', 'label' => 'الشحنات', 'group' => 'تشغيل', 'description' => 'الشحنات والأوزان والتكاليف وحالة الاعتماد.'],
            ['key' => 'weighbridge', 'label' => 'كروت الميزان', 'group' => 'تشغيل', 'description' => 'المحمل والفارغ والخصم والصافي وحالة الكرت.'],
            ['key' => 'shipment-profit', 'label' => 'ربحية الشحنات', 'group' => 'ربحية', 'description' => 'المباع وتكلفته والربح المحقق والمخزون المتبقي لكل شحنة.'],
            ['key' => 'car-profit', 'label' => 'ربحية السيارات', 'group' => 'ربحية', 'description' => 'تجميع ربحية الشحنات حسب السيارة.'],
            ['key' => 'item-profit', 'label' => 'ربحية الأصناف', 'group' => 'ربحية', 'description' => 'إيراد وتكلفة وربح الكميات المباعة حسب الصنف.'],
            ['key' => 'customer-balances', 'label' => 'أرصدة العملاء', 'group' => 'أرصدة', 'description' => 'المبيعات والمقبوض والمدفوع والرصيد لكل عميل.'],
            ['key' => 'supplier-balances', 'label' => 'أرصدة الموردين', 'group' => 'أرصدة', 'description' => 'المشتريات والمدفوع والمقبوض والرصيد لكل مورد.'],
            ['key' => 'driver-balances', 'label' => 'أرصدة السائقين', 'group' => 'أرصدة', 'description' => 'المصروفات والسندات ورصيد السائق.'],
            ['key' => 'fixed-assets', 'label' => 'الأصول الثابتة', 'group' => 'أصول', 'description' => 'تكلفة الأصل والقيمة الدفترية والإهلاك والحالة والفرع.'],
            ['key' => 'asset-depreciation', 'label' => 'إهلاك الأصول', 'group' => 'أصول', 'description' => 'قيود الإهلاك الشهرية والقيم الافتتاحية والختامية.'],
            ['key' => 'asset-maintenance', 'label' => 'صيانة الأصول', 'group' => 'أصول', 'description' => 'تكاليف الصيانة ومعالجتها وحالة السداد.'],
            ['key' => 'workers', 'label' => 'العاملون والموظفون', 'group' => 'موارد بشرية', 'description' => 'بيانات العامل والوظيفة ونوع الراتب والحالة.'],
            ['key' => 'payroll', 'label' => 'مسيرات الرواتب', 'group' => 'موارد بشرية', 'description' => 'مسيرات الرواتب وصافي المستحق والمدفوع وحالة السداد.'],
            ['key' => 'worker-loans', 'label' => 'سلف العاملين', 'group' => 'موارد بشرية', 'description' => 'السلف حسب العامل والفرع وطريقة الدفع.'],
            ['key' => 'worker-commissions', 'label' => 'عمولات العاملين', 'group' => 'موارد بشرية', 'description' => 'العمولات المرتبطة بالعامل والشحنة أو فاتورة البيع.'],
            ['key' => 'shipment-costs', 'label' => 'تكاليف الشحنات', 'group' => 'تشغيل', 'description' => 'التكاليف المباشرة المرسملة على الشحنات وحالة توزيعها.'],
            ['key' => 'sales-items', 'label' => 'تفاصيل أصناف المبيعات', 'group' => 'مالية', 'description' => 'تفاصيل الأصناف والكميات والأسعار والضريبة لكل فاتورة بيع.'],
            ['key' => 'purchase-items', 'label' => 'تفاصيل أصناف المشتريات', 'group' => 'مالية', 'description' => 'تفاصيل الأصناف والكميات والأسعار والضريبة لكل فاتورة شراء.'],
        ];
    }

    public function run(int $companyId, ?int $branchId, string $key, array $f = []): array
    {
        $key = strtolower(trim($key));
        return match ($key) {
            'executive' => $this->executive($companyId, $branchId, $f),
            'sales' => $this->sales($companyId, $branchId, $f),
            'purchases' => $this->purchases($companyId, $branchId, $f),
            'expenses' => $this->expenses($companyId, $branchId, $f),
            'vouchers' => $this->vouchers($companyId, $branchId, $f),
            'journal-entries' => $this->journalEntries($companyId, $branchId, $f),
            'trial-balance' => $this->trialBalance($companyId, $branchId, $f),
            'income-statement' => $this->incomeStatement($companyId, $branchId, $f),
            'balance-sheet' => $this->balanceSheet($companyId, $branchId, $f),
            'inventory' => $this->inventory($companyId, $branchId, $f),
            'inventory-lots' => $this->inventoryLots($companyId, $branchId, $f),
            'inventory-movements' => $this->inventoryMovements($companyId, $branchId, $f),
            'inventory-operations' => $this->inventoryOperations($companyId, $branchId, $f),
            'shipments' => $this->shipments($companyId, $branchId, $f),
            'weighbridge' => $this->weighbridge($companyId, $branchId, $f),
            'shipment-profit' => $this->shipmentProfit($companyId, $branchId, $f),
            'car-profit' => $this->carProfit($companyId, $branchId, $f),
            'item-profit' => $this->itemProfit($companyId, $branchId, $f),
            'customer-balances' => $this->partyBalances($companyId, $branchId, 'CUSTOMER', $f),
            'supplier-balances' => $this->partyBalances($companyId, $branchId, 'SUPPLIER', $f),
            'driver-balances' => $this->partyBalances($companyId, $branchId, 'DRIVER', $f),
            'fixed-assets' => $this->fixedAssets($companyId, $branchId, $f),
            'asset-depreciation' => $this->assetDepreciation($companyId, $branchId, $f),
            'asset-maintenance' => $this->assetMaintenance($companyId, $branchId, $f),
            'workers' => $this->workers($companyId, $branchId, $f),
            'payroll' => $this->payroll($companyId, $branchId, $f),
            'worker-loans' => $this->workerLoans($companyId, $branchId, $f),
            'worker-commissions' => $this->workerCommissions($companyId, $branchId, $f),
            'shipment-costs' => $this->shipmentCosts($companyId, $branchId, $f),
            'sales-items' => $this->salesItems($companyId, $branchId, $f),
            'purchase-items' => $this->purchaseItems($companyId, $branchId, $f),
            default => throw new \RuntimeException('نوع التقرير غير معروف.'),
        };
    }

    private function executive(int $cid, ?int $bid, array $f): array
    {
        $salesQ = DB::table('sales_invoices')->where('company_id', $cid);
        $purchasesQ = DB::table('purchase_invoices')->where('company_id', $cid);
        $expensesQ = DB::table('expenses')->where('company_id', $cid);
        $this->branch($salesQ, $bid); $this->branch($purchasesQ, $bid); $this->branch($expensesQ, $bid);
        $this->dates($salesQ, 'invoice_date', $f); $this->dates($purchasesQ, 'invoice_date', $f); $this->dates($expensesQ, 'expense_date', $f);

        $revenue = round((float) $salesQ->sum('total_before_vat'), 3);
        $purchases = round((float) $purchasesQ->sum('total_before_vat'), 3);
        $expenses = round((float) $expensesQ->sum('amount'), 3);

        $costQ = DB::table('sales_line_lot_sources as s')
            ->join('sales_invoice_lines as sl', 'sl.id', '=', 's.sales_invoice_line_id')
            ->join('sales_invoices as i', 'i.id', '=', 'sl.sales_invoice_id')
            ->where('s.company_id', $cid);
        if ($bid !== null) $costQ->where('s.branch_id', $bid);
        $this->dates($costQ, 'i.invoice_date', $f);
        $cogs = round((float) $costQ->sum('s.total_cost'), 3);

        $stockQ = DB::table('inventory_lots')->where('company_id', $cid)->where('qty_remaining_kg', '>', 0);
        $this->branch($stockQ, $bid);
        $stock = $stockQ->selectRaw('COALESCE(SUM(qty_remaining_kg),0) kg, COALESCE(SUM(CASE WHEN qty_received_kg>0 THEN total_cost*(qty_remaining_kg/qty_received_kg) ELSE 0 END),0) value')->first();

        $rows = collect([
            ['indicator' => 'إيراد المبيعات قبل الضريبة', 'value' => $revenue],
            ['indicator' => 'تكلفة البضاعة المباعة', 'value' => $cogs],
            ['indicator' => 'مجمل ربح المبيعات', 'value' => round($revenue - $cogs, 3)],
            ['indicator' => 'المصروفات التشغيلية', 'value' => $expenses],
            ['indicator' => 'النتيجة التشغيلية المبسطة', 'value' => round($revenue - $cogs - $expenses, 3)],
            ['indicator' => 'المشتريات قبل الضريبة', 'value' => $purchases],
            ['indicator' => 'رصيد المخزون كجم', 'value' => round((float) ($stock->kg ?? 0), 3)],
            ['indicator' => 'قيمة المخزون', 'value' => round((float) ($stock->value ?? 0), 3)],
        ]);

        return $this->pack('الملخص التنفيذي', [
            $this->col('indicator', 'المؤشر'),
            $this->col('value', 'القيمة', 'number'),
        ], $rows, [
            'sales_revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => round($revenue - $cogs, 3),
            'expenses' => $expenses,
            'operating_result' => round($revenue - $cogs - $expenses, 3),
            'stock_value' => round((float) ($stock->value ?? 0), 3),
        ]);
    }

    private function sales(int $cid, ?int $bid, array $f): array
    {
        $q = DB::table('sales_invoices as s')
            ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.company_id', $cid);
        if ($bid !== null) $q->where('s.branch_id', $bid);
        $this->dates($q, 's.invoice_date', $f); $this->search($q, $f, ['s.invoice_number','c.customer_name']);
        $rows = $q->select('s.id','s.invoice_number','s.invoice_date','b.branch_name','c.customer_name','s.total_qty','s.total_before_vat','s.vat_amount','s.total_amount','s.payment_status')->orderByDesc('s.invoice_date')->orderByDesc('s.id')->limit(5000)->get();
        return $this->pack('تقرير المبيعات', [
            $this->col('invoice_number','رقم الفاتورة'),$this->col('invoice_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('customer_name','العميل'),$this->col('total_qty','الكمية','number'),$this->col('total_before_vat','قبل الضريبة','number'),$this->col('vat_amount','الضريبة','number'),$this->col('total_amount','الإجمالي','number'),$this->col('payment_status','السداد'),
        ], $rows, ['count'=>$rows->count(),'total_before_vat'=>round((float)$rows->sum('total_before_vat'),3),'vat'=>round((float)$rows->sum('vat_amount'),3),'total'=>round((float)$rows->sum('total_amount'),3)]);
    }

    private function purchases(int $cid, ?int $bid, array $f): array
    {
        $q = DB::table('purchase_invoices as p')
            ->leftJoin('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')
            ->where('p.company_id', $cid);
        if ($bid !== null) $q->where('p.branch_id', $bid);
        $this->dates($q, 'p.invoice_date', $f); $this->search($q, $f, ['p.invoice_number','s.supplier_name']);
        $rows = $q->select('p.id','p.invoice_number','p.invoice_date','b.branch_name','s.supplier_name','p.total_qty','p.total_before_vat','p.vat_amount','p.total_amount','p.payment_status')->orderByDesc('p.invoice_date')->orderByDesc('p.id')->limit(5000)->get();
        return $this->pack('تقرير المشتريات', [
            $this->col('invoice_number','رقم الفاتورة'),$this->col('invoice_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('supplier_name','المورد'),$this->col('total_qty','الكمية','number'),$this->col('total_before_vat','قبل الضريبة','number'),$this->col('vat_amount','الضريبة','number'),$this->col('total_amount','الإجمالي','number'),$this->col('payment_status','السداد'),
        ], $rows, ['count'=>$rows->count(),'total_before_vat'=>round((float)$rows->sum('total_before_vat'),3),'vat'=>round((float)$rows->sum('vat_amount'),3),'total'=>round((float)$rows->sum('total_amount'),3)]);
    }

    private function expenses(int $cid, ?int $bid, array $f): array
    {
        $q = DB::table('expenses as e')->leftJoin('expense_types as t','t.id','=','e.expense_type_id')->leftJoin('branches as b','b.id','=','e.branch_id')->where('e.company_id',$cid);
        if($bid!==null)$q->where('e.branch_id',$bid);$this->dates($q,'e.expense_date',$f);$this->search($q,$f,['t.type_name','e.notes']);
        $rows=$q->select('e.id','e.expense_date','b.branch_name','t.type_name','e.amount','e.payment_status','e.payment_method','e.notes')->orderByDesc('e.expense_date')->orderByDesc('e.id')->limit(5000)->get();
        return $this->pack('تقرير المصروفات',[$this->col('expense_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('type_name','نوع المصروف'),$this->col('amount','المبلغ','number'),$this->col('payment_status','السداد'),$this->col('payment_method','الطريقة'),$this->col('notes','البيان')],$rows,['count'=>$rows->count(),'total'=>round((float)$rows->sum('amount'),3)]);
    }

    private function vouchers(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('vouchers as v')->leftJoin('voucher_types as t','t.id','=','v.voucher_type_id')->leftJoin('branches as b','b.id','=','v.branch_id')->where('v.company_id',$cid);
        if($bid!==null)$q->where('v.branch_id',$bid);$this->dates($q,'v.voucher_date',$f);$this->search($q,$f,['v.voucher_number','v.reference_type','v.notes']);
        $rows=$q->select('v.id','v.voucher_number','v.voucher_date','b.branch_name','t.type_name','t.type_code','v.reference_type','v.reference_id','v.amount','v.payment_method','v.notes')->orderByDesc('v.voucher_date')->orderByDesc('v.id')->limit(5000)->get();
        return $this->pack('سندات القبض والصرف',[$this->col('voucher_number','رقم السند'),$this->col('voucher_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('type_name','النوع'),$this->col('reference_type','الطرف'),$this->col('amount','المبلغ','number'),$this->col('payment_method','طريقة الدفع'),$this->col('notes','البيان')],$rows,['count'=>$rows->count(),'total'=>round((float)$rows->sum('amount'),3)]);
    }

    private function journalEntries(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('journal_entries as e')->leftJoin('branches as b','b.id','=','e.branch_id')->where('e.company_id',$cid);
        if($bid!==null)$q->where('e.branch_id',$bid);$this->dates($q,'e.entry_date',$f);$this->search($q,$f,['e.entry_number','e.reference_no','e.description','e.source_type']);
        $rows=$q->select('e.id','e.entry_number','e.entry_date','e.reference_no','e.source_type','b.branch_name','e.description','e.status','e.reversed_at',DB::raw('(SELECT COALESCE(SUM(l.debit),0) FROM journal_entry_lines l WHERE l.journal_entry_id=e.id) debit_total'),DB::raw('(SELECT COALESCE(SUM(l.credit),0) FROM journal_entry_lines l WHERE l.journal_entry_id=e.id) credit_total'))->orderByDesc('e.entry_date')->orderByDesc('e.id')->limit(5000)->get();
        return $this->pack('دفتر اليومية',[$this->col('entry_number','رقم القيد'),$this->col('entry_date','التاريخ','date'),$this->col('reference_no','المرجع'),$this->col('source_type','المصدر'),$this->col('branch_name','الفرع'),$this->col('description','البيان'),$this->col('debit_total','مدين','number'),$this->col('credit_total','دائن','number'),$this->col('status','الحالة')],$rows,['count'=>$rows->count(),'debit'=>round((float)$rows->sum('debit_total'),3),'credit'=>round((float)$rows->sum('credit_total'),3)]);
    }

    private function trialBalance(int $cid, ?int $bid, array $f): array
    {
        $d = $this->accounting->trialBalance($cid, $bid, $f);
        $rows = collect($d['rows'])->map(fn ($r) => (object) $r);
        return $this->pack('ميزان المراجعة', [
            $this->col('account_code','رمز الحساب'),
            $this->col('account_name','اسم الحساب'),
            $this->col('opening_debit','افتتاحي مدين','number'),
            $this->col('opening_credit','افتتاحي دائن','number'),
            $this->col('period_debit','حركة مدين','number'),
            $this->col('period_credit','حركة دائن','number'),
            $this->col('closing_debit','ختامي مدين','number'),
            $this->col('closing_credit','ختامي دائن','number'),
        ], $rows, [
            'opening_debit'=>round((float)$d['totals']['opening_debit'],3),
            'opening_credit'=>round((float)$d['totals']['opening_credit'],3),
            'debit'=>round((float)$d['totals']['period_debit'],3),
            'credit'=>round((float)$d['totals']['period_credit'],3),
            'closing_debit'=>round((float)$d['totals']['closing_debit'],3),
            'closing_credit'=>round((float)$d['totals']['closing_credit'],3),
            'difference'=>round((float)$d['totals']['difference'],3),
        ]);
    }

    private function incomeStatement(int $cid, ?int $bid, array $f): array
    {
        $d = $this->accounting->incomeStatement($cid, $bid, $f);
        $rows = collect();
        foreach ($d['revenues'] as $r) $rows->push((object)[...$r,'section'=>'الإيرادات']);
        foreach ($d['cost_of_revenue'] as $r) $rows->push((object)[...$r,'section'=>'تكلفة الإيراد']);
        foreach ($d['operating_expenses'] as $r) $rows->push((object)[...$r,'section'=>'المصروفات التشغيلية']);
        return $this->pack('قائمة الدخل', [
            $this->col('section','التصنيف'),
            $this->col('account_code','رمز الحساب'),
            $this->col('account_name','الحساب'),
            $this->col('amount','القيمة','number'),
        ], $rows, [
            'revenue'=>round((float)$d['revenue_total'],3),
            'cost'=>round((float)$d['cost_of_revenue_total'],3),
            'gross_profit'=>round((float)$d['gross_profit'],3),
            'expenses'=>round((float)$d['operating_expenses_total'],3),
            'net_result'=>round((float)$d['net_result'],3),
        ]);
    }

    private function balanceSheet(int $cid, ?int $bid, array $f): array
    {
        $asOf = $f['to_date'] ?? now()->toDateString();
        $d = $this->accounting->balanceSheet($cid, $bid, ['as_of'=>$asOf]);
        $rows = collect();
        foreach ($d['assets'] as $r) $rows->push((object)[...$r,'section'=>'الأصول']);
        foreach ($d['liabilities'] as $r) $rows->push((object)[...$r,'section'=>'الالتزامات']);
        foreach ($d['equity'] as $r) $rows->push((object)[...$r,'section'=>'حقوق الملكية']);
        if (abs((float)$d['current_period_result']) > 0.0001) {
            $rows->push((object)['section'=>'حقوق الملكية','account_code'=>'—','account_name'=>'نتيجة الفترة الحالية','amount'=>$d['current_period_result']]);
        }
        return $this->pack('قائمة المركز المالي حتى ' . $asOf, [
            $this->col('section','التصنيف'),
            $this->col('account_code','رمز الحساب'),
            $this->col('account_name','الحساب'),
            $this->col('amount','القيمة','number'),
        ], $rows, [
            'total_assets'=>round((float)$d['total_assets'],3),
            'total_liabilities'=>round((float)$d['total_liabilities'],3),
            'total_equity'=>round((float)$d['total_equity'],3),
            'liabilities_equity'=>round((float)$d['total_liabilities_equity'],3),
            'difference'=>round((float)$d['difference'],3),
        ]);
    }

    private function inventory(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('inventory_lots as l')->join('items as i','i.id','=','l.item_id')->leftJoin('branches as b','b.id','=','l.branch_id')->where('l.company_id',$cid);
        if($bid!==null)$q->where('l.branch_id',$bid);$this->search($q,$f,['i.item_code','i.item_name','i.item_grade']);
        $rows=$q->select('l.branch_id','l.item_id','b.branch_name','i.item_code','i.item_name','i.item_grade',DB::raw('SUM(l.qty_remaining_kg) balance_kg'),DB::raw('SUM(CASE WHEN l.qty_received_kg>0 THEN l.total_cost*(l.qty_remaining_kg/l.qty_received_kg) ELSE 0 END) stock_value'),DB::raw('SUM(CASE WHEN l.qty_remaining_kg>0 THEN 1 ELSE 0 END) open_lots'))->groupBy('l.branch_id','l.item_id','b.branch_name','i.item_code','i.item_name','i.item_grade')->havingRaw('SUM(l.qty_remaining_kg) > 0.0001')->orderBy('i.item_name')->get()->map(function($r){$r->balance_ton=round((float)$r->balance_kg/1000,3);$r->avg_cost_per_kg=(float)$r->balance_kg>0?round((float)$r->stock_value/(float)$r->balance_kg,6):0;$r->avg_cost_per_ton=round($r->avg_cost_per_kg*1000,3);return $r;});
        return $this->pack('أرصدة وقيمة المخزون',[$this->col('branch_name','الفرع'),$this->col('item_code','الكود'),$this->col('item_name','الصنف'),$this->col('item_grade','الدرجة'),$this->col('balance_kg','الرصيد كجم','number'),$this->col('balance_ton','الرصيد طن','number'),$this->col('avg_cost_per_ton','متوسط تكلفة الطن','number'),$this->col('stock_value','قيمة المخزون','number'),$this->col('open_lots','دفعات مفتوحة','number')],$rows,['items'=>$rows->count(),'balance_kg'=>round((float)$rows->sum('balance_kg'),3),'stock_value'=>round((float)$rows->sum('stock_value'),3)]);
    }

    private function inventoryLots(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('inventory_lots as l')->join('items as i','i.id','=','l.item_id')->leftJoin('branches as b','b.id','=','l.branch_id')->leftJoin('shipments as s','s.id','=','l.shipment_id')->where('l.company_id',$cid);
        if($bid!==null)$q->where('l.branch_id',$bid);$this->dates($q,'l.received_at',$f);$this->search($q,$f,['l.lot_number','i.item_code','i.item_name','s.shipment_number','l.source_type']);
        $rows=$q->select('l.id','l.lot_number','l.received_at','b.branch_name','i.item_code','i.item_name','s.shipment_number','l.source_type','l.qty_received_kg','l.qty_remaining_kg','l.qty_sold_kg','l.base_cost','l.allocated_cost','l.total_cost','l.unit_cost_per_kg','l.lot_status')->orderByDesc('l.received_at')->orderByDesc('l.id')->limit(5000)->get();
        return $this->pack('دفعات المخزون',[$this->col('lot_number','رقم الدفعة'),$this->col('received_at','تاريخ الاستلام','date'),$this->col('branch_name','الفرع'),$this->col('item_name','الصنف'),$this->col('shipment_number','الشحنة'),$this->col('source_type','المصدر'),$this->col('qty_received_kg','الوارد كجم','number'),$this->col('qty_remaining_kg','المتبقي كجم','number'),$this->col('unit_cost_per_kg','تكلفة الكجم','number'),$this->col('total_cost','تكلفة الدفعة','number'),$this->col('lot_status','الحالة')],$rows,['count'=>$rows->count(),'received_kg'=>round((float)$rows->sum('qty_received_kg'),3),'remaining_kg'=>round((float)$rows->sum('qty_remaining_kg'),3),'total_cost'=>round((float)$rows->sum('total_cost'),3)]);
    }

    private function inventoryMovements(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('inventory_lot_movements as m')->join('inventory_lots as l','l.id','=','m.inventory_lot_id')->join('items as i','i.id','=','m.item_id')->leftJoin('branches as b','b.id','=','m.branch_id')->where('m.company_id',$cid);
        if($bid!==null)$q->where('m.branch_id',$bid);$this->dates($q,'m.movement_at',$f);$this->search($q,$f,['l.lot_number','i.item_name','m.source_type','m.notes']);
        $rows=$q->select('m.id','m.movement_at','b.branch_name','l.lot_number','i.item_name','m.movement_type','m.source_type','m.source_id','m.qty_kg','m.unit_cost_per_kg','m.total_cost','m.notes')->orderByDesc('m.movement_at')->orderByDesc('m.id')->limit(5000)->get();
        return $this->pack('حركة المخزون',[$this->col('movement_at','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('lot_number','الدفعة'),$this->col('item_name','الصنف'),$this->col('movement_type','الحركة'),$this->col('source_type','المصدر'),$this->col('qty_kg','الكمية كجم','number'),$this->col('unit_cost_per_kg','تكلفة الكجم','number'),$this->col('total_cost','التكلفة','number'),$this->col('notes','البيان')],$rows,['count'=>$rows->count(),'in_kg'=>round((float)$rows->where('movement_type','IN')->sum('qty_kg'),3),'out_kg'=>round((float)$rows->where('movement_type','OUT')->sum('qty_kg'),3)]);
    }

    private function inventoryOperations(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('inventory_operations as o')->leftJoin('branches as fb','fb.id','=','o.from_branch_id')->leftJoin('branches as tb','tb.id','=','o.to_branch_id')->where('o.company_id',$cid);
        if($bid!==null)$q->where('o.from_branch_id',$bid);$this->dates($q,'o.operation_date',$f);$this->search($q,$f,['o.operation_number','o.operation_type','o.notes']);
        $rows=$q->select('o.id','o.operation_number','o.operation_date','o.operation_type','fb.branch_name as from_branch_name','tb.branch_name as to_branch_name','o.input_weight_kg','o.output_weight_kg','o.loss_qty_kg','o.gain_qty_kg','o.loss_gain_reason','o.allocation_method','o.status',DB::raw("(SELECT COALESCE(SUM(l.total_cost),0) FROM inventory_operation_lines l WHERE l.operation_id=o.id AND l.line_type='FROM') input_cost"))->orderByDesc('o.operation_date')->orderByDesc('o.id')->limit(5000)->get();
        return $this->pack('الفرز والتحويل والمعالجة',[$this->col('operation_number','رقم العملية'),$this->col('operation_date','التاريخ','date'),$this->col('operation_type','النوع'),$this->col('from_branch_name','من فرع'),$this->col('to_branch_name','إلى فرع'),$this->col('input_weight_kg','مدخل كجم','number'),$this->col('output_weight_kg','مخرج كجم','number'),$this->col('loss_qty_kg','فاقد كجم','number'),$this->col('gain_qty_kg','زيادة كجم','number'),$this->col('input_cost','التكلفة','number'),$this->col('status','الحالة')],$rows,['count'=>$rows->count(),'input_kg'=>round((float)$rows->sum('input_weight_kg'),3),'output_kg'=>round((float)$rows->sum('output_weight_kg'),3),'loss_kg'=>round((float)$rows->sum('loss_qty_kg'),3)]);
    }

    private function shipments(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('shipments as s')->leftJoin('suppliers as sp','sp.id','=','s.supplier_id')->leftJoin('cars as c','c.id','=','s.car_id')->leftJoin('branches as b','b.id','=','s.branch_id')->leftJoin('weighbridge_cards as w','w.id','=','s.weighbridge_card_id')->where('s.company_id',$cid);
        if($bid!==null)$q->where('s.branch_id',$bid);$this->dates($q,'s.shipment_date',$f);$this->search($q,$f,['s.shipment_number','sp.supplier_name','c.plate_number','w.card_number']);
        $rows=$q->select('s.id','s.shipment_number','s.shipment_date','b.branch_name','sp.supplier_name','c.plate_number','w.card_number','w.loaded_weight_kg','w.empty_weight_kg','w.deduction_weight_kg','w.net_weight_kg','s.total_amount','s.distributed_cost','s.costing_status','s.status')->orderByDesc('s.shipment_date')->orderByDesc('s.id')->limit(5000)->get();
        return $this->pack('تقرير الشحنات',[$this->col('shipment_number','الشحنة'),$this->col('shipment_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('supplier_name','المورد'),$this->col('plate_number','السيارة'),$this->col('card_number','كرت الميزان'),$this->col('loaded_weight_kg','محمل كجم','number'),$this->col('empty_weight_kg','فارغ كجم','number'),$this->col('deduction_weight_kg','خصم كجم','number'),$this->col('net_weight_kg','صافي كجم','number'),$this->col('total_amount','قيمة الشراء','number'),$this->col('distributed_cost','تكاليف مباشرة','number'),$this->col('status','الحالة')],$rows,['count'=>$rows->count(),'net_kg'=>round((float)$rows->sum('net_weight_kg'),3),'purchase_total'=>round((float)$rows->sum('total_amount'),3),'direct_costs'=>round((float)$rows->sum('distributed_cost'),3)]);
    }

    private function weighbridge(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('weighbridge_cards as w')->leftJoin('shipments as s','s.id','=','w.shipment_id')->leftJoin('cars as c','c.id','=','w.car_id')->leftJoin('branches as b','b.id','=','w.branch_id')->where('w.company_id',$cid);
        if($bid!==null)$q->where('w.branch_id',$bid);$this->dates($q,'w.opened_at',$f);$this->search($q,$f,['w.card_number','s.shipment_number','c.plate_number','w.external_ticket_number']);
        $rows=$q->select('w.id','w.card_number','w.opened_at','w.closed_at','b.branch_name','s.shipment_number','c.plate_number','w.loaded_weight_kg','w.empty_weight_kg','w.deduction_weight_kg','w.net_weight_kg','w.scale_name','w.external_ticket_number','w.status')->orderByDesc('w.opened_at')->orderByDesc('w.id')->limit(5000)->get();
        return $this->pack('تقرير كروت الميزان',[$this->col('card_number','الكرت'),$this->col('opened_at','فتح الكرت','date'),$this->col('branch_name','الفرع'),$this->col('shipment_number','الشحنة'),$this->col('plate_number','السيارة'),$this->col('loaded_weight_kg','محمل كجم','number'),$this->col('empty_weight_kg','فارغ كجم','number'),$this->col('deduction_weight_kg','الخصم','number'),$this->col('net_weight_kg','الصافي','number'),$this->col('scale_name','الميزان'),$this->col('external_ticket_number','التذكرة'),$this->col('status','الحالة')],$rows,['count'=>$rows->count(),'net_kg'=>round((float)$rows->sum('net_weight_kg'),3)]);
    }

    private function itemProfit(int $cid, ?int $bid, array $f): array
    {
        $costSub=DB::table('sales_line_lot_sources')->select('sales_invoice_line_id',DB::raw('SUM(total_cost) cost_total'))->where('company_id',$cid)->groupBy('sales_invoice_line_id');
        $q=DB::table('sales_invoice_lines as sl')->join('sales_invoices as s','s.id','=','sl.sales_invoice_id')->join('items as i','i.id','=','sl.item_id')->leftJoinSub($costSub,'c','c.sales_invoice_line_id','=','sl.id')->where('sl.company_id',$cid)->where('s.company_id',$cid);
        if($bid!==null)$q->where('s.branch_id',$bid);$this->dates($q,'s.invoice_date',$f);$this->search($q,$f,['i.item_code','i.item_name']);
        $rows=$q->select('sl.item_id','i.item_code','i.item_name',DB::raw('SUM(sl.qty*1000) sold_kg'),DB::raw('SUM(COALESCE(NULLIF(sl.total_before_vat,0), sl.line_total-sl.vat_amount)) revenue'),DB::raw('SUM(COALESCE(c.cost_total,0)) cost_total'))->groupBy('sl.item_id','i.item_code','i.item_name')->orderByDesc('revenue')->get()->map(function($r){$r->sold_ton=round((float)$r->sold_kg/1000,3);$r->profit=round((float)$r->revenue-(float)$r->cost_total,3);$r->margin_percent=(float)$r->revenue>0?round($r->profit/(float)$r->revenue*100,2):0;return $r;});
        return $this->pack('ربحية الأصناف',[$this->col('item_code','الكود'),$this->col('item_name','الصنف'),$this->col('sold_kg','المباع كجم','number'),$this->col('sold_ton','المباع طن','number'),$this->col('revenue','الإيراد','number'),$this->col('cost_total','التكلفة','number'),$this->col('profit','الربح','number'),$this->col('margin_percent','هامش %','number')],$rows,['revenue'=>round((float)$rows->sum('revenue'),3),'cost'=>round((float)$rows->sum('cost_total'),3),'profit'=>round((float)$rows->sum('profit'),3)]);
    }

    private function shipmentProfit(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('shipments as sh')->leftJoin('cars as car','car.id','=','sh.car_id')->leftJoin('suppliers as sp','sp.id','=','sh.supplier_id')->leftJoin('branches as b','b.id','=','sh.branch_id')->where('sh.company_id',$cid);
        if($bid!==null)$q->where('sh.branch_id',$bid);$this->dates($q,'sh.shipment_date',$f);$this->search($q,$f,['sh.shipment_number','car.plate_number','sp.supplier_name']);
        $shipments=$q->select('sh.id','sh.shipment_number','sh.shipment_date','b.branch_name','car.plate_number','sp.supplier_name','sh.total_net_weight_kg','sh.total_amount','sh.distributed_cost')->orderByDesc('sh.shipment_date')->limit(5000)->get();
        $rows=$shipments->map(function($r) use($cid){
            $sources=DB::table('sales_line_lot_sources as src')->join('sales_invoice_lines as sl','sl.id','=','src.sales_invoice_line_id')->join('sales_invoices as si','si.id','=','sl.sales_invoice_id')->where('src.company_id',$cid)->where('src.shipment_id',$r->id)->select('src.*','sl.total_before_vat','sl.line_total','sl.vat_amount')->get();
            $revenue=0.0;$cost=0.0;$soldKg=0.0;
            foreach($sources as $s){$lineQty=(float)DB::table('sales_line_lot_sources')->where('company_id',$cid)->where('sales_invoice_line_id',$s->sales_invoice_line_id)->sum('qty_kg');$lineRevenue=(float)($s->total_before_vat ?: ((float)$s->line_total-(float)$s->vat_amount));$share=$lineQty>0?(float)$s->qty_kg/$lineQty:0;$revenue+=round($lineRevenue*$share,3);$cost+=(float)$s->total_cost;$soldKg+=(float)$s->qty_kg;}
            $remaining=DB::table('inventory_lots')->where('company_id',$cid)->where('shipment_id',$r->id)->selectRaw('COALESCE(SUM(qty_remaining_kg),0) kg, COALESCE(SUM(CASE WHEN qty_received_kg>0 THEN total_cost*(qty_remaining_kg/qty_received_kg) ELSE 0 END),0) value')->first();
            $r->sold_kg=round($soldKg,3);$r->sales_revenue=round($revenue,3);$r->cogs=round($cost,3);$r->realized_profit=round($revenue-$cost,3);$r->remaining_kg=round((float)($remaining->kg??0),3);$r->remaining_value=round((float)($remaining->value??0),3);return $r;
        });
        return $this->pack('ربحية الشحنات',[$this->col('shipment_number','الشحنة'),$this->col('shipment_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('plate_number','السيارة'),$this->col('supplier_name','المورد'),$this->col('total_net_weight_kg','وزن الشحنة كجم','number'),$this->col('sold_kg','المباع كجم','number'),$this->col('sales_revenue','إيراد مباع','number'),$this->col('cogs','تكلفة المباع','number'),$this->col('realized_profit','ربح محقق','number'),$this->col('remaining_kg','متبقي كجم','number'),$this->col('remaining_value','قيمة المتبقي','number')],$rows,['revenue'=>round((float)$rows->sum('sales_revenue'),3),'cogs'=>round((float)$rows->sum('cogs'),3),'profit'=>round((float)$rows->sum('realized_profit'),3),'remaining_value'=>round((float)$rows->sum('remaining_value'),3)]);
    }

    private function carProfit(int $cid, ?int $bid, array $f): array
    {
        $ship=$this->shipmentProfit($cid,$bid,$f)['rows'];
        $groups=[];
        foreach($ship as $r){$key=$r->plate_number ?: 'بدون سيارة';if(!isset($groups[$key]))$groups[$key]=['plate_number'=>$key,'shipments'=>0,'shipment_kg'=>0.0,'sold_kg'=>0.0,'revenue'=>0.0,'cogs'=>0.0,'profit'=>0.0,'remaining_value'=>0.0];$groups[$key]['shipments']++;$groups[$key]['shipment_kg']+=(float)$r->total_net_weight_kg;$groups[$key]['sold_kg']+=(float)$r->sold_kg;$groups[$key]['revenue']+=(float)$r->sales_revenue;$groups[$key]['cogs']+=(float)$r->cogs;$groups[$key]['profit']+=(float)$r->realized_profit;$groups[$key]['remaining_value']+=(float)$r->remaining_value;}
        $rows=collect(array_values($groups))->map(fn($r)=>(object)array_map(fn($v)=>is_float($v)?round($v,3):$v,$r))->sortByDesc('profit')->values();
        return $this->pack('ربحية السيارات',[$this->col('plate_number','السيارة'),$this->col('shipments','عدد الشحنات','number'),$this->col('shipment_kg','أوزان الشحنات كجم','number'),$this->col('sold_kg','المباع كجم','number'),$this->col('revenue','الإيراد','number'),$this->col('cogs','تكلفة المباع','number'),$this->col('profit','الربح المحقق','number'),$this->col('remaining_value','قيمة المتبقي','number')],$rows,['revenue'=>round((float)$rows->sum('revenue'),3),'cogs'=>round((float)$rows->sum('cogs'),3),'profit'=>round((float)$rows->sum('profit'),3)]);
    }

    private function partyBalances(int $cid, ?int $bid, string $type, array $f): array
    {
        $map=[
            'CUSTOMER'=>['table'=>'customers','id'=>'customer_id','name'=>'customer_name','invoice'=>'sales_invoices','invoice_party'=>'customer_id','invoice_date'=>'invoice_date','invoice_total'=>'total_amount','label'=>'أرصدة العملاء'],
            'SUPPLIER'=>['table'=>'suppliers','id'=>'supplier_id','name'=>'supplier_name','invoice'=>'purchase_invoices','invoice_party'=>'supplier_id','invoice_date'=>'invoice_date','invoice_total'=>'total_amount','label'=>'أرصدة الموردين'],
            'DRIVER'=>['table'=>'drivers','id'=>'driver_id','name'=>'driver_name','invoice'=>null,'label'=>'أرصدة السائقين'],
        ];
        $m=$map[$type];$q=DB::table($m['table'])->where('company_id',$cid);if($bid!==null)$q->where(function($x)use($bid){$x->whereNull('branch_id')->orWhere('branch_id',$bid);});$this->search($q,$f,[$m['name'],'phone']);$entities=$q->select('id',$m['name'],'phone')->orderBy($m['name'])->get();
        $rows=$entities->map(function($e)use($cid,$bid,$type,$m,$f){$activity=0.0;if($m['invoice']){$iq=DB::table($m['invoice'])->where('company_id',$cid)->where($m['invoice_party'],$e->id);if($bid!==null)$iq->where('branch_id',$bid);$this->dates($iq,$m['invoice_date'],$f);$activity=(float)$iq->sum($m['invoice_total']);}elseif($type==='DRIVER'){$eq=DB::table('expenses')->where('company_id',$cid)->where('driver_id',$e->id);if($bid!==null)$eq->where('branch_id',$bid);$this->dates($eq,'expense_date',$f);$activity=(float)$eq->sum('amount');}
            $payments=0.0;$receipts=0.0;$vq=DB::table('vouchers as v')->leftJoin('voucher_types as t','t.id','=','v.voucher_type_id')->where('v.company_id',$cid)->where('v.reference_type',$type)->where('v.reference_id',$e->id);if($bid!==null)$vq->where('v.branch_id',$bid);$this->dates($vq,'v.voucher_date',$f);$payments=(float)(clone $vq)->where('t.type_code','PAYMENT')->sum('v.amount');$receipts=(float)(clone $vq)->where('t.type_code','RECEIPT')->sum('v.amount');$opening=0.0;if(in_array($type,['CUSTOMER','SUPPLIER'],true)){$oq=DB::table('journal_entry_lines as l')->join('journal_entries as j','j.id','=','l.journal_entry_id')->where('l.company_id',$cid)->where('j.status','POSTED')->where('j.source_type','OPENING_BALANCE')->where('l.party_type',$type)->where('l.party_id',$e->id);if($bid!==null)$oq->where('l.branch_id',$bid);$opening=(float)$oq->sum(DB::raw($type==='CUSTOMER'?'l.debit-l.credit':'l.credit-l.debit'));}
            $balance=$type==='CUSTOMER'?($opening+$activity+$payments-$receipts):($opening+$activity+$receipts-$payments);
            return (object)['party_id'=>$e->id,'party_name'=>$e->{$m['name']},'phone'=>$e->phone,'opening_balance'=>round($opening,3),'activity'=>round($activity,3),'payments'=>round($payments,3),'receipts'=>round($receipts,3),'balance'=>round($balance,3)];});
        return $this->pack($m['label'],[$this->col('party_name',$type==='CUSTOMER'?'العميل':($type==='SUPPLIER'?'المورد':'السائق')),$this->col('phone','الهاتف'),$this->col('opening_balance','افتتاحي','number'),$this->col('activity',$type==='CUSTOMER'?'مبيعات':($type==='SUPPLIER'?'مشتريات':'مصروفات'),'number'),$this->col('payments','مدفوع','number'),$this->col('receipts','مقبوض','number'),$this->col('balance','الرصيد','number')],$rows,['count'=>$rows->count(),'balance'=>round((float)$rows->sum('balance'),3)]);
    }


    private function fixedAssets(int $cid, ?int $bid, array $f): array
    {
        $q = DB::table('fixed_assets as a')
            ->leftJoin('fixed_asset_categories as c', 'c.id', '=', 'a.category_id')
            ->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
            ->where('a.company_id', $cid);
        if ($bid !== null) $q->where('a.branch_id', $bid);
        $this->dates($q, 'a.purchase_date', $f);
        $this->search($q, $f, ['a.asset_code','a.asset_name','a.serial_number','c.category_name','a.location']);
        $rows = $q->select('a.id','a.asset_code','a.asset_name','c.category_name','b.branch_name','a.purchase_date','a.purchase_cost','a.salvage_value','a.accumulated_depreciation','a.current_book_value','a.asset_status','a.location')
            ->orderBy('a.asset_code')->limit(5000)->get();
        return $this->pack('الأصول الثابتة', [
            $this->col('asset_code','رمز الأصل'),$this->col('asset_name','اسم الأصل'),$this->col('category_name','التصنيف'),
            $this->col('branch_name','الفرع'),$this->col('purchase_date','تاريخ الشراء','date'),$this->col('purchase_cost','تكلفة الشراء','number'),
            $this->col('salvage_value','قيمة الخردة','number'),$this->col('accumulated_depreciation','مجمع الإهلاك','number'),
            $this->col('current_book_value','القيمة الدفترية','number'),$this->col('asset_status','الحالة'),$this->col('location','الموقع'),
        ], $rows, [
            'count'=>$rows->count(),
            'purchase_cost'=>round((float)$rows->sum('purchase_cost'),3),
            'accumulated_depreciation'=>round((float)$rows->sum('accumulated_depreciation'),3),
            'book_value'=>round((float)$rows->sum('current_book_value'),3),
        ]);
    }

    private function assetDepreciation(int $cid, ?int $bid, array $f): array
    {
        $q = DB::table('fixed_asset_depreciation as d')
            ->join('fixed_assets as a', 'a.id', '=', 'd.asset_id')
            ->leftJoin('branches as b', 'b.id', '=', 'd.branch_id')
            ->where('d.company_id', $cid);
        if ($bid !== null) $q->where('d.branch_id', $bid);
        $this->dates($q, 'd.depreciation_month', $f);
        $this->search($q, $f, ['a.asset_code','a.asset_name','d.status']);
        $rows = $q->select('d.id','d.depreciation_month','a.asset_code','a.asset_name','b.branch_name','d.opening_book_value','d.depreciation_amount','d.accumulated_depreciation','d.closing_book_value','d.status','d.journal_entry_id')
            ->orderByDesc('d.depreciation_month')->orderBy('a.asset_code')->limit(5000)->get();
        return $this->pack('إهلاك الأصول', [
            $this->col('depreciation_month','الشهر','date'),$this->col('asset_code','رمز الأصل'),$this->col('asset_name','الأصل'),$this->col('branch_name','الفرع'),
            $this->col('opening_book_value','افتتاحي','number'),$this->col('depreciation_amount','الإهلاك','number'),
            $this->col('accumulated_depreciation','مجمع الإهلاك','number'),$this->col('closing_book_value','ختامي','number'),$this->col('status','الحالة'),
        ], $rows, ['count'=>$rows->count(),'depreciation'=>round((float)$rows->sum('depreciation_amount'),3)]);
    }

    private function assetMaintenance(int $cid, ?int $bid, array $f): array
    {
        $q = DB::table('fixed_asset_maintenance as m')
            ->join('fixed_assets as a', 'a.id', '=', 'm.asset_id')
            ->leftJoin('branches as b', 'b.id', '=', 'm.branch_id')
            ->where('m.company_id', $cid);
        if ($bid !== null) $q->where('m.branch_id', $bid);
        $this->dates($q, 'm.maintenance_date', $f);
        $this->search($q, $f, ['a.asset_code','a.asset_name','m.maintenance_type','m.supplier_name','m.invoice_number']);
        $rows = $q->select('m.id','m.maintenance_date','a.asset_code','a.asset_name','b.branch_name','m.maintenance_type','m.supplier_name','m.invoice_number','m.maintenance_cost','m.cost_treatment','m.status','m.description')
            ->orderByDesc('m.maintenance_date')->limit(5000)->get();
        return $this->pack('صيانة الأصول', [
            $this->col('maintenance_date','التاريخ','date'),$this->col('asset_code','رمز الأصل'),$this->col('asset_name','الأصل'),$this->col('branch_name','الفرع'),
            $this->col('maintenance_type','نوع الصيانة'),$this->col('supplier_name','المورد'),$this->col('invoice_number','الفاتورة'),
            $this->col('maintenance_cost','التكلفة','number'),$this->col('cost_treatment','المعالجة'),$this->col('status','الحالة'),$this->col('description','البيان'),
        ], $rows, ['count'=>$rows->count(),'total'=>round((float)$rows->sum('maintenance_cost'),3)]);
    }

    private function workers(int $cid, ?int $bid, array $f): array
    {
        $q = DB::table('workers as w')->leftJoin('branches as b','b.id','=','w.branch_id')->where('w.company_id',$cid);
        if ($bid !== null) $q->where('w.branch_id',$bid);
        $this->search($q,$f,['w.employee_no','w.worker_name','w.job_title','w.department','w.phone']);
        $rows=$q->select('w.id','w.employee_no','w.worker_name','b.branch_name','w.job_title','w.department','w.salary_type','w.salary_rate','w.hire_date','w.contract_type','w.worker_status','w.phone')
            ->orderBy('w.worker_name')->limit(5000)->get();
        return $this->pack('العاملون والموظفون',[
            $this->col('employee_no','الرقم الوظيفي'),$this->col('worker_name','الاسم'),$this->col('branch_name','الفرع'),$this->col('job_title','الوظيفة'),
            $this->col('department','القسم'),$this->col('salary_type','نوع الأجر'),$this->col('salary_rate','الأجر','number'),$this->col('hire_date','تاريخ التعيين','date'),
            $this->col('contract_type','العقد'),$this->col('worker_status','الحالة'),$this->col('phone','الهاتف')
        ],$rows,['count'=>$rows->count()]);
    }

    private function payroll(int $cid, ?int $bid, array $f): array
    {
        $q = DB::table('worker_salary_lines as l')
            ->join('worker_salary_runs as r','r.id','=','l.salary_run_id')
            ->join('workers as w','w.id','=','l.worker_id')
            ->leftJoin('branches as b','b.id','=','r.branch_id')
            ->where('l.company_id',$cid)->where('r.company_id',$cid);
        if($bid!==null)$q->where('r.branch_id',$bid);
        $this->dates($q,'r.salary_month',$f);$this->search($q,$f,['r.run_number','w.worker_name','l.payment_status']);
        $rows=$q->select('l.id','r.salary_month','r.run_number','b.branch_name','w.worker_name','l.basic_amount','l.overtime_amount','l.allowance_amount','l.bonus_amount','l.commission_amount','l.loan_deduction','l.other_deduction','l.net_salary','l.payment_status','l.payment_method')
            ->orderByDesc('r.salary_month')->orderBy('w.worker_name')->limit(5000)->get();
        return $this->pack('مسيرات الرواتب',[
            $this->col('salary_month','الشهر','date'),$this->col('run_number','المسير'),$this->col('branch_name','الفرع'),$this->col('worker_name','العامل'),
            $this->col('basic_amount','الأساسي','number'),$this->col('overtime_amount','إضافي','number'),$this->col('allowance_amount','بدلات','number'),
            $this->col('bonus_amount','مكافآت','number'),$this->col('commission_amount','عمولات','number'),$this->col('loan_deduction','خصم سلف','number'),
            $this->col('other_deduction','خصومات أخرى','number'),$this->col('net_salary','الصافي','number'),$this->col('payment_status','السداد'),$this->col('payment_method','الطريقة')
        ],$rows,['count'=>$rows->count(),'net_salary'=>round((float)$rows->sum('net_salary'),3)]);
    }

    private function workerLoans(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('worker_loans as l')->join('workers as w','w.id','=','l.worker_id')->leftJoin('branches as b','b.id','=','l.branch_id')->where('l.company_id',$cid);
        if($bid!==null)$q->where('l.branch_id',$bid);$this->dates($q,'l.loan_date',$f);$this->search($q,$f,['w.worker_name','l.payment_method','l.notes']);
        $rows=$q->select('l.id','l.loan_date','b.branch_name','w.worker_name','l.amount','l.payment_method','l.voucher_id','l.journal_entry_id','l.notes')->orderByDesc('l.loan_date')->limit(5000)->get();
        return $this->pack('سلف العاملين',[$this->col('loan_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('worker_name','العامل'),$this->col('amount','المبلغ','number'),$this->col('payment_method','طريقة الدفع'),$this->col('notes','ملاحظات')],$rows,['count'=>$rows->count(),'total'=>round((float)$rows->sum('amount'),3)]);
    }

    private function workerCommissions(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('worker_commissions as c')->join('workers as w','w.id','=','c.worker_id')->leftJoin('branches as b','b.id','=','c.branch_id')->leftJoin('shipments as s','s.id','=','c.shipment_id')->leftJoin('sales_invoices as i','i.id','=','c.sales_invoice_id')->where('c.company_id',$cid);
        if($bid!==null)$q->where('c.branch_id',$bid);$this->dates($q,'c.commission_date',$f);$this->search($q,$f,['w.worker_name','s.shipment_number','i.invoice_number','c.status']);
        $rows=$q->select('c.id','c.commission_date','b.branch_name','w.worker_name','s.shipment_number','i.invoice_number','c.amount','c.status','c.paid_at','c.notes')->orderByDesc('c.commission_date')->limit(5000)->get();
        return $this->pack('عمولات العاملين',[$this->col('commission_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('worker_name','العامل'),$this->col('shipment_number','الشحنة'),$this->col('invoice_number','فاتورة البيع'),$this->col('amount','العمولة','number'),$this->col('status','الحالة'),$this->col('paid_at','تاريخ السداد')],$rows,['count'=>$rows->count(),'total'=>round((float)$rows->sum('amount'),3)]);
    }

    private function shipmentCosts(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('shipment_costs as c')->join('shipments as s','s.id','=','c.shipment_id')->leftJoin('expense_types as t','t.id','=','c.expense_type_id')->leftJoin('branches as b','b.id','=','c.branch_id')->where('c.company_id',$cid);
        if($bid!==null)$q->where('c.branch_id',$bid);$this->dates($q,'c.expense_date',$f);$this->search($q,$f,['s.shipment_number','t.type_name','c.notes']);
        $rows=$q->select('c.id','c.expense_date','b.branch_name','s.shipment_number','t.type_name','c.amount','c.payment_status','c.payment_method','c.distributed','c.notes')->orderByDesc('c.expense_date')->limit(5000)->get()
            ->map(function($r){$r->distributed_label=((int)$r->distributed===1)?'موزعة':'غير موزعة';return $r;});
        return $this->pack('تكاليف الشحنات',[$this->col('expense_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('shipment_number','الشحنة'),$this->col('type_name','نوع التكلفة'),$this->col('amount','المبلغ','number'),$this->col('payment_status','السداد'),$this->col('payment_method','الطريقة'),$this->col('distributed_label','التوزيع'),$this->col('notes','ملاحظات')],$rows,['count'=>$rows->count(),'total'=>round((float)$rows->sum('amount'),3)]);
    }

    private function salesItems(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('sales_invoice_lines as l')->join('sales_invoices as s','s.id','=','l.sales_invoice_id')->join('items as i','i.id','=','l.item_id')->leftJoin('customers as c','c.id','=','s.customer_id')->leftJoin('branches as b','b.id','=','s.branch_id')->where('l.company_id',$cid)->where('s.company_id',$cid);
        if($bid!==null)$q->where('s.branch_id',$bid);$this->dates($q,'s.invoice_date',$f);$this->search($q,$f,['s.invoice_number','i.item_code','i.item_name','c.customer_name']);
        $rows=$q->select('l.id','s.invoice_number','s.invoice_date','b.branch_name','c.customer_name','i.item_code','i.item_name','l.qty','l.unit_price','l.discount_amount','l.vat_amount','l.total_before_vat','l.total_after_vat')->orderByDesc('s.invoice_date')->orderByDesc('l.id')->limit(5000)->get();
        return $this->pack('تفاصيل أصناف المبيعات',[$this->col('invoice_number','الفاتورة'),$this->col('invoice_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('customer_name','العميل'),$this->col('item_code','الكود'),$this->col('item_name','الصنف'),$this->col('qty','الكمية طن','number'),$this->col('unit_price','سعر الطن','number'),$this->col('discount_amount','الخصم','number'),$this->col('vat_amount','الضريبة','number'),$this->col('total_before_vat','قبل الضريبة','number'),$this->col('total_after_vat','الإجمالي','number')],$rows,['count'=>$rows->count(),'qty'=>round((float)$rows->sum('qty'),3),'total'=>round((float)$rows->sum('total_after_vat'),3)]);
    }

    private function purchaseItems(int $cid, ?int $bid, array $f): array
    {
        $q=DB::table('purchase_invoice_lines as l')->join('purchase_invoices as p','p.id','=','l.purchase_invoice_id')->join('items as i','i.id','=','l.item_id')->leftJoin('suppliers as s','s.id','=','p.supplier_id')->leftJoin('branches as b','b.id','=','p.branch_id')->where('l.company_id',$cid)->where('p.company_id',$cid);
        if($bid!==null)$q->where('p.branch_id',$bid);$this->dates($q,'p.invoice_date',$f);$this->search($q,$f,['p.invoice_number','i.item_code','i.item_name','s.supplier_name']);
        $rows=$q->select('l.id','p.invoice_number','p.invoice_date','b.branch_name','s.supplier_name','i.item_code','i.item_name','l.qty','l.unit_price','l.discount_amount','l.vat_amount','l.total_before_vat','l.total_after_vat')->orderByDesc('p.invoice_date')->orderByDesc('l.id')->limit(5000)->get();
        return $this->pack('تفاصيل أصناف المشتريات',[$this->col('invoice_number','الفاتورة'),$this->col('invoice_date','التاريخ','date'),$this->col('branch_name','الفرع'),$this->col('supplier_name','المورد'),$this->col('item_code','الكود'),$this->col('item_name','الصنف'),$this->col('qty','الكمية طن','number'),$this->col('unit_price','سعر الطن','number'),$this->col('discount_amount','الخصم','number'),$this->col('vat_amount','الضريبة','number'),$this->col('total_before_vat','قبل الضريبة','number'),$this->col('total_after_vat','الإجمالي','number')],$rows,['count'=>$rows->count(),'qty'=>round((float)$rows->sum('qty'),3),'total'=>round((float)$rows->sum('total_after_vat'),3)]);
    }

    private function pack(string $title, array $columns, $rows, array $summary = []): array
    {
        return ['title'=>$title,'columns'=>$columns,'rows'=>collect($rows)->values(),'summary'=>$summary,'generated_at'=>now()->toDateTimeString()];
    }
    private function col(string $key,string $label,string $type='text'): array{return ['key'=>$key,'label'=>$label,'type'=>$type];}
    private function branch(Builder $q,?int $bid,string $column='branch_id'): void{if($bid!==null)$q->where($column,$bid);}
    private function dates(Builder $q,string $column,array $f): void{if(!empty($f['from_date']))$q->whereDate($column,'>=',$f['from_date']);if(!empty($f['to_date']))$q->whereDate($column,'<=',$f['to_date']);}
    private function search(Builder $q,array $f,array $cols): void{$s=trim((string)($f['q']??''));if($s==='')return;$q->where(function($x)use($cols,$s){foreach($cols as $i=>$c){$i===0?$x->where($c,'like','%'.$s.'%'):$x->orWhere($c,'like','%'.$s.'%');}});}
}
