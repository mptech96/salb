<?php

namespace App\Services;

use App\Domain\Accounting\Services\JournalService;
use App\Services\Accounting\EnterpriseAccountingPostingService;
use App\Services\Accounting\ItemAccountingResolver;
use Illuminate\Support\Facades\DB;

/**
 * Commercial invoice lifecycle for SULB ERP.
 *
 * Important separation of responsibilities:
 *  - Weighbridge = physical evidence only.
 *  - Shipment     = operational preparation/pricing/costs only.
 *  - Invoice POST = inventory + subledger + general ledger.
 */
class EnterpriseInvoiceService
{
    public function __construct(
        private PartyBranchScopeService $parties,
        private TaxEngineService $taxes,
        private EntityAddressService $addresses,
        private FinancialAccountService $money,
        private DocumentNumberService $numbers,
        private InventoryLotService $lots,
        private EnterpriseAccountingPostingService $accounting,
        private ItemAccountingResolver $itemAccounts,
        private DefaultPartyService $defaultParties,
        private ShipmentCostService $shipmentCosts,
        private JournalService $journals,
    ) {}

    public function saveDraft(
        string $mode,
        array $data,
        int $companyId,
        int $branchId,
        int $userId,
        ?int $invoiceId = null,
        ?\App\Services\Uom\CapturedDocumentSnapshot $capturedSource = null
    ): int {
        $mode = strtoupper($mode);
        $sale = $mode === 'SALE';
        if (!in_array($mode, ['SALE','PURCHASE'], true)) {
            throw new \RuntimeException('نوع الفاتورة غير مدعوم.');
        }

        $table = $sale ? 'sales_invoices' : 'purchase_invoices';
        $lineTable = $sale ? 'sales_invoice_lines' : 'purchase_invoice_lines';
        $fk = $sale ? 'sales_invoice_id' : 'purchase_invoice_id';
        $partyKey = $sale ? 'customer_id' : 'supplier_id';
        $partyType = $sale ? 'CUSTOMER' : 'SUPPLIER';

        return DB::transaction(function () use (
            $mode,$sale,$data,$companyId,$branchId,$userId,$invoiceId,$capturedSource,
            $table,$lineTable,$fk,$partyKey,$partyType
        ) {
            $old = $invoiceId
                ? DB::table($table)->where('company_id',$companyId)->where('id',$invoiceId)->lockForUpdate()->first()
                : null;

            if ($invoiceId && !$old) throw new \RuntimeException('الفاتورة غير موجودة.');
            if ($old && (($old->document_status ?? ($old->journal_entry_id ? 'POSTED' : 'DRAFT')) !== 'DRAFT' || $old->journal_entry_id)) {
                throw new \RuntimeException('الفاتورة المرحلة لا تعدل مباشرة. استخدم العكس/التصحيح.');
            }
            if ($old && (int)$old->branch_id !== $branchId) {
                throw new \RuntimeException('لا يمكن نقل مسودة الفاتورة إلى فرع آخر. أنشئ مسودة جديدة.');
            }

            $partyId = (int)($data[$partyKey] ?? 0);
            if (!$partyId) {
                $defaults=$this->defaultParties->ensure($companyId,$userId);
                $partyId=(int)($sale?$defaults['default_customer_id']:$defaults['default_supplier_id']);
            }
            $this->parties->assertAccessible($companyId,$partyType,$partyId,$branchId);

            $date = (string)($data['invoice_date'] ?? '');
            if (!$date) throw new \RuntimeException('تاريخ الفاتورة مطلوب.');

            $currency = strtoupper(trim((string)($data['currency_code'] ?? $this->money->baseCurrency($companyId))));
            $rate = isset($data['exchange_rate']) && $data['exchange_rate'] !== ''
                ? (float)$data['exchange_rate']
                : $this->money->rate($companyId,$currency,$date);
            if ($rate <= 0) throw new \RuntimeException('سعر الصرف غير صالح.');

            $shipmentIds = array_values(array_unique(array_filter(
                array_map('intval', $data['shipment_ids'] ?? []),
                fn ($x) => $x > 0
            )));

            // Manual lines are allowed together with shipment-derived lines.
            $manualLines = array_values(array_filter(
                $data['items'] ?? [],
                fn ($r) => empty($r['shipment_id']) && (int)($r['item_id'] ?? 0) > 0
            ));
            $lines = $manualLines;
            if ($shipmentIds) {
                $lines = array_merge(
                    $this->shipmentLines($mode,$companyId,$branchId,$partyId,$shipmentIds,$invoiceId),
                    $manualLines
                );
            }
            if (!$lines) throw new \RuntimeException('أضف صنفاً واحداً على الأقل أو اختر شحنة جاهزة.');

            $prepared = [];
            $netBeforeHeader = 0.0;
            $qtyKgTotal = 0.0;

            foreach ($lines as $i => $row) {
                $itemId = (int)($row['item_id'] ?? 0);
                $item = DB::table('items')
                    ->where('company_id',$companyId)
                    ->where('id',$itemId)
                    ->where('is_active',1)
                    ->first();
                if (!$item) throw new \RuntimeException('الصنف في السطر '.($i+1).' غير صالح.');
                if ($sale && !(int)($item->can_sell ?? 1)) throw new \RuntimeException('الصنف '.$item->item_name.' غير مسموح ببيعه.');
                if (!$sale && !(int)($item->can_purchase ?? 1)) throw new \RuntimeException('الصنف '.$item->item_name.' غير مسموح بشرائه.');

                $isService=strtoupper((string)($item->item_type??'STOCK'))==='SERVICE'||(int)($item->track_inventory??1)!==1;
                $uom=null;
                if(!$isService&&($item->inventory_quantity_version??'LEGACY_KG')==='BASE_V1'){
                    if(!empty($row['shipment_id'])){
                        // shipmentLines has already locked and scoped the READY header.
                        $saved=DB::table('shipment_items')->where('company_id',$companyId)->where('shipment_id',$row['shipment_id'])->where('id',$row['shipment_item_id'])->where('item_id',$itemId)->lockForUpdate()->first();
                        if(!$saved||!\App\Services\Uom\OperationalDocument::isBase($saved))throw new \RuntimeException('UOM_SHIPMENT_SNAPSHOT_SCOPE');
                        if(strtoupper((string)$saved->currency_code)!==$currency)throw new \RuntimeException('UOM_SHIPMENT_CURRENCY_MISMATCH');
                        $uom=\App\Services\Uom\OperationalDocument::prepareSnapshot($companyId,$item,$saved);
                    }else $uom=$capturedSource?$capturedSource->prepare($companyId,$branchId,$item,$row,$sale):\App\Services\Uom\OperationalDocument::prepare($companyId,$item,$row,$sale);
                    $quantity=$uom['quantity'];$unitCode=$uom['unit_code'];$qtyKg=0.0;$priceUnit='UNIT';
                    $enteredPrice=$uom['price'];$perKg=0.0;$gross=(float)$uom['gross'];
                }elseif($isService){
                    $quantity=round((float)($row['quantity']??$row['qty']??$row['qty_kg']??0),6);
                    if($quantity<=0)throw new \RuntimeException('كمية الخدمة في السطر '.($i+1).' يجب أن تكون أكبر من صفر.');
                    $qtyKg=0.0;$priceUnit='UNIT';$unitCode=strtoupper((string)($row['unit_code']??$item->base_unit_code??'UNIT'));
                    $enteredPrice=round((float)($row['unit_price']??0),6);$perKg=0.0;$gross=round($quantity*$enteredPrice,3);
                }else{
                    $qtyKg=isset($row['qty_kg'])?round((float)$row['qty_kg'],3):round((float)($row['qty']??0)*1000,3);
                    if($qtyKg<=0)throw new \RuntimeException('الكمية في السطر '.($i+1).' يجب أن تكون أكبر من صفر.');
                    $quantity=$qtyKg;$unitCode='KG';
                    $priceUnit=strtoupper((string)($row['price_unit']??(array_key_exists('qty_kg',$row)?'KG':'TON')));
                    if(!in_array($priceUnit,['KG','TON'],true))$priceUnit='KG';
                    $enteredPrice=round((float)($row['unit_price']??0),6);
                    $perKg=$priceUnit==='TON'?round($enteredPrice/1000,6):$enteredPrice;
                    $gross=round($qtyKg*$perKg,3);
                }
                $lineDiscount = round((float)($row['discount_amount'] ?? 0),3);
                if ($lineDiscount < 0 || $lineDiscount > $gross + 0.0001) {
                    throw new \RuntimeException('خصم السطر '.($i+1).' غير صالح.');
                }
                $net = max(0,round($gross - $lineDiscount,3));

                $prepared[] = [
                    ...$row,
                    'item'=>$item,
                    'item_id'=>$itemId,
                    'quantity'=>$quantity,
                    'unit_code'=>$unitCode,
                    'unit_factor_to_base'=>$uom?$uom['factor']:1,
                    'uom_columns'=>$uom?$uom['columns']:[],
                    'qty_kg'=>$qtyKg,
                    'qty_ton'=>$isService?$quantity:round($qtyKg/1000,6),
                    'price_unit'=>$priceUnit,
                    'entered_price'=>$enteredPrice,
                    'unit_price_per_kg'=>$perKg,
                    'legacy_unit_price'=>($isService||$uom)?$enteredPrice:round($perKg*1000,3),
                    'line_discount'=>$lineDiscount,
                    'gross'=>$gross,
                    'net'=>$net,
                ];
                $netBeforeHeader += $net;
                $qtyKgTotal += $qtyKg;
            }

            $headerDiscount = round((float)($data['discount_amount'] ?? 0),3);
            $commission = $sale ? round((float)($data['commission_amount'] ?? 0),3) : 0.0;
            $headerReduction = $headerDiscount + $commission;
            if ($headerReduction > $netBeforeHeader + 0.0001) {
                throw new \RuntimeException('خصومات رأس الفاتورة أكبر من قيمة الأصناف.');
            }

            $taxPrepared = [];
            $itemsBeforeVat = 0.0;
            $vat = 0.0;
            foreach ($prepared as $row) {
                $share = $netBeforeHeader > 0 ? $row['net'] / $netBeforeHeader : 0;
                $allocatedHeaderDiscount = round($headerReduction * $share,3);
                $tax = $this->taxes->line(
                    $companyId,
                    $row['gross'],
                    $row['line_discount'] + $allocatedHeaderDiscount,
                    isset($row['tax_code_id']) && $row['tax_code_id'] !== '' ? (int)$row['tax_code_id'] : null,
                    $date,
                    $sale ? 'SALES' : 'PURCHASE',
                    isset($row['vat_percent']) ? (float)$row['vat_percent'] : null
                );
                $taxPrepared[] = [...$row,...$tax,'allocated_header_discount'=>$allocatedHeaderDiscount];
                $itemsBeforeVat += (float)$tax['total_before_vat'];
                $vat += (float)$tax['vat_amount'];
            }

            // Header costs are kept only for purchase compatibility. Operational shipment costs
            // should normally be entered on each shipment and are posted/capitalized at invoice POST.
            $transport = round((float)($data['transport_cost'] ?? 0),3);
            $extra = round((float)($data['extra_cost'] ?? 0),3);
            $itemsBeforeVat = round($itemsBeforeVat,3);
            $vat = round($vat,3);
            $beforeVat = round($itemsBeforeVat + ($sale ? 0 : $transport + $extra),3);
            $total = round($beforeVat + $vat,3);
            $baseBefore = round($beforeVat * $rate,3);
            $baseVat = round($vat * $rate,3);
            $baseTotal = round($total * $rate,3);

            $manualNo = trim((string)($data['invoice_number'] ?? ''));
            if ($manualNo !== '') {
                $this->numbers->assertManualUnique($companyId,$table,$manualNo,$invoiceId);
                $invoiceNo = $manualNo;
            } else {
                $settings = DB::table('company_settings')->where('company_id',$companyId)->first();
                $invoiceNo = $old?->invoice_number ?: $this->numbers->next(
                    $companyId,
                    $branchId,
                    $sale ? 'SALE' : 'PURCHASE',
                    strtoupper((string)($data['document_type'] ?? 'TAX_INVOICE')),
                    $date,
                    $sale ? ($settings->invoice_prefix ?? null) : ($settings->purchase_prefix ?? null)
                );
            }

            $seller = $sale
                ? $this->addresses->snapshotCompanyAndBranch($companyId,$branchId)
                : $this->addresses->snapshotParty($companyId,'SUPPLIER',$partyId);
            $buyer = $sale
                ? $this->addresses->snapshotParty($companyId,'CUSTOMER',$partyId)
                : $this->addresses->snapshotCompanyAndBranch($companyId,$branchId);

            $header = [
                'company_id'=>$companyId,
                'branch_id'=>$branchId,
                $partyKey=>$partyId,
                'car_id'=>isset($data['car_id']) && (int)$data['car_id']>0 ? (int)$data['car_id'] : null,
                'invoice_number'=>$invoiceNo,
                'invoice_date'=>$date,
                'document_type'=>strtoupper((string)($data['document_type'] ?? 'TAX_INVOICE')),
                'currency_code'=>$currency,
                'exchange_rate'=>$rate,
                'seller_snapshot_json'=>json_encode($seller,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'buyer_snapshot_json'=>json_encode($buyer,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'tax_summary_json'=>json_encode($this->taxes->summary($taxPrepared),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'total_qty'=>round($qtyKgTotal/1000,6),
                'total_before_discount'=>round($netBeforeHeader,3),
                'discount_amount'=>$headerDiscount,
                'vat_amount'=>$vat,
                'total_before_vat'=>$beforeVat,
                'total_after_vat'=>$total,
                'total_amount'=>$total,
                'base_total_before_vat'=>$baseBefore,
                'base_vat_amount'=>$baseVat,
                'base_total_amount'=>$baseTotal,
                'document_status'=>'DRAFT',
                'notes'=>$data['notes'] ?? null,
                'updated_at'=>now(),
            ];
            if ($sale) {
                $header['commission_amount']=$commission;
                $header['transport_cost']=$transport;
                $header['extra_cost']=$extra;
            } else {
                $header['transport_cost']=$transport;
                $header['extra_cost']=$extra;
            }

            if ($invoiceId) {
                DB::table($table)->where('id',$invoiceId)->update($header);
                $id=$invoiceId;
                DB::table($lineTable)->where('company_id',$companyId)->where($fk,$id)->delete();
                DB::table('invoice_shipment_links')
                    ->where('company_id',$companyId)->where('invoice_type',$mode)->where('invoice_id',$id)->delete();
            } else {
                $header['payment_status']='UNPAID';
                $header['created_by']=$userId;
                $header['created_at']=now();
                $id=DB::table($table)->insertGetId($header);
            }

            foreach ($taxPrepared as $row) {
                DB::table($lineTable)->insert([
                    ...$row['uom_columns'],
                    'company_id'=>$companyId,
                    $fk=>$id,
                    'item_id'=>$row['item_id'],
                    'car_id'=>isset($data['car_id']) && (int)$data['car_id']>0 ? (int)$data['car_id'] : null,
                    'shipment_id'=>$row['shipment_id'] ?? null,
                    'shipment_item_id'=>$row['shipment_item_id'] ?? null,
                    'qty'=>$row['qty_ton'],
                    'quantity'=>$row['quantity'],
                    'unit_code'=>$row['unit_code'],
                    'unit_factor_to_base'=>$row['unit_factor_to_base'],
                    'qty_kg'=>$row['qty_kg'],
                    'price_unit'=>$row['price_unit'],
                    'unit_price_per_kg'=>$row['unit_price_per_kg'],
                    // Legacy column remains price/ton for compatibility with older reports.
                    'unit_price'=>$row['legacy_unit_price'],
                    'discount_amount'=>$row['line_discount'] + $row['allocated_header_discount'],
                    'item_type_snapshot'=>$row['item']->item_type ?? 'STOCK',
                    'track_inventory_snapshot'=>(int)($row['item']->track_inventory ?? 1),
                    'tax_code_id'=>$row['tax_code_id'],
                    'tax_code_snapshot'=>$row['tax_code_snapshot'],
                    'tax_name_snapshot'=>$row['tax_name_snapshot'],
                    'tax_rate_snapshot'=>$row['tax_rate_snapshot'],
                    'vat_percent'=>$row['tax_rate_snapshot'],
                    'vat_amount'=>$row['vat_amount'],
                    'total_before_vat'=>$row['total_before_vat'],
                    'total_after_vat'=>$row['total_after_vat'],
                    'line_total'=>$row['line_total'],
                    'currency_code'=>$currency,
                    'exchange_rate'=>$rate,
                    'base_total_before_vat'=>round($row['total_before_vat']*$rate,3),
                    'base_vat_amount'=>round($row['vat_amount']*$rate,3),
                    'base_total_after_vat'=>round($row['total_after_vat']*$rate,3),
                    'notes'=>$row['notes'] ?? null,
                    'created_at'=>now(),
                    'updated_at'=>now(),
                ]);
            }

            foreach ($shipmentIds as $shipmentId) {
                $qty = round((float)DB::table('shipment_items')
                    ->where('company_id',$companyId)->where('shipment_id',$shipmentId)->sum('accepted_qty_kg'),3);
                $amount = round((float)DB::table('shipment_items')
                    ->where('company_id',$companyId)->where('shipment_id',$shipmentId)->sum('total_before_vat'),3);
                DB::table('invoice_shipment_links')->insert([
                    'company_id'=>$companyId,'branch_id'=>$branchId,'invoice_type'=>$mode,
                    'invoice_id'=>$id,'shipment_id'=>$shipmentId,'allocated_qty_kg'=>$qty,
                    'allocated_amount'=>$amount,'created_at'=>now(),'updated_at'=>now(),
                ]);
            }

            return $id;
        });
    }

    public function post(string $mode,int $companyId,int $invoiceId,int $userId,?int $branchFilter=null,?callable $isolatedCapture=null): array
    {
        // Transport metadata never creates ownership. At the HTTP entry all
        // existing auth/subscription/tenant middleware has already run.
        if(request()->exists('transaction_owner')||request()->exists('trusted_transaction_owner')||request()->headers->has('X-Sulb-Transaction-Owner'))
            throw new \RuntimeException('TRANSACTION_OWNER_NOT_ACCEPTED: هوية مالك المعاملة داخلية ولا تُقبل من الطلب.');
        if($isolatedCapture!==null)\App\Services\Accounting\IsolatedHistoricalAccountingGate::assert();
        if($isolatedCapture!==null){
            $actorBranch=$branchFilter??(int)DB::table(strtoupper($mode)==='SALE'?'sales_invoices':'purchase_invoices')->where('company_id',$companyId)->where('id',$invoiceId)->value('branch_id');
            \App\Services\Accounting\HistoricalAccountingAuthorization::assert($companyId,$actorBranch,$userId,[strtoupper($mode)==='SALE'?'sales.post':'purchases.post']);
        }
        $mode=strtoupper($mode);
        $sale=$mode==='SALE';
        $table=$sale?'sales_invoices':'purchase_invoices';
        $lineTable=$sale?'sales_invoice_lines':'purchase_invoice_lines';
        $fk=$sale?'sales_invoice_id':'purchase_invoice_id';

        $uomOwned=app(\App\Services\Uom\OperationalTransactionOwner::class)->owns($companyId,$invoiceId,$userId,$sale?'SALE_POST':'PURCHASE_POST');
        $ownedScope=app(ShipmentTransactionOwner::class)->scope($companyId,$invoiceId,$userId,$sale?'SALE_POST':'PURCHASE_POST');
        if($sale&&$ownedScope===null&&(DB::transactionLevel()>0||DB::connection()->getPdo()->inTransaction())
            &&DB::table('invoice_shipment_links')->where('company_id',$companyId)->where('invoice_type','SALE')->where('invoice_id',$invoiceId)->exists())
            throw new \RuntimeException('SALE_POST_OUTERMOST_REQUIRED');
        $shipmentScope=$sale?[]:($ownedScope??ShipmentPurchasePostingScope::snapshot($companyId,$invoiceId));
        if(!$sale&&$ownedScope===null)ShipmentPurchasePostingScope::assertOuterEntry($shipmentScope);
        $restoreIsolation=(!$sale&&$ownedScope===null)?ShipmentCostReadIsolation::prepare($shipmentScope):null;
        try { return DB::transaction(function()use($mode,$sale,$companyId,$invoiceId,$userId,$branchFilter,$table,$lineTable,$fk,$isolatedCapture,$shipmentScope,$ownedScope,$uomOwned){
            if(!$sale)ShipmentPurchasePostingScope::reserve($companyId,$shipmentScope);
            $inv=DB::table($table)->where('company_id',$companyId)->where('id',$invoiceId)->lockForUpdate()->first();
            if(!$inv)throw new \RuntimeException('الفاتورة غير موجودة.');
            if($branchFilter!==null&&(int)$inv->branch_id!==$branchFilter)throw new \RuntimeException('الفاتورة خارج نطاق فرعك.');
            if(!$sale)ShipmentPurchasePostingScope::verify($companyId,$invoiceId,(int)$inv->branch_id,$shipmentScope);
            if(($inv->document_status??'DRAFT')==='POSTED'||$inv->journal_entry_id){
                return ['id'=>$invoiceId,'journal_entry_id'=>$inv->journal_entry_id,'message'=>'الفاتورة مرحلة مسبقًا.'];
            }
            if(($inv->document_status??'DRAFT')!=='DRAFT')throw new \RuntimeException('حالة الفاتورة لا تسمح بالترحيل.');
            if(!$sale)ShipmentPurchasePostingScope::assertReady($companyId,(int)$inv->branch_id,(int)$inv->supplier_id,$shipmentScope);

            $linkedShipments=(!$sale&&!$shipmentScope)?collect():DB::table('invoice_shipment_links')->where('company_id',$companyId)->where('invoice_type',$mode)->where('invoice_id',$invoiceId)
                ->orderBy('shipment_id')->lockForUpdate()->get();
            foreach($linkedShipments as$link)if((int)$link->branch_id!==(int)$inv->branch_id)throw new \RuntimeException('SHIPMENT_SCOPE_BRANCH_MISMATCH');
            // H precedes I/L/F for linked SALE as well. Purchase already owns H.
            foreach($linkedShipments->pluck('shipment_id')->unique()->sort() as$sid)if(!DB::table('shipments')->where('company_id',$companyId)->where('id',$sid)->lockForUpdate()->first())throw new \RuntimeException('SHIPMENT_SCOPE_SOURCE_MISSING');

            // Source -> company/year LOT scope (purchase only) -> sorted items -> existing FY.
            // Reserve before any inventory/FY locks, including custom opening lot numbers.
            if(!$sale&&DB::table($lineTable)->where('company_id',$companyId)->where($fk,$invoiceId)
                ->where('track_inventory_snapshot',1)->where('item_type_snapshot','STOCK')->exists()){
                InventoryPostingLocks::reserveLotScope($companyId,(string)$inv->invoice_date);
            }
            $invoiceItemQuery=DB::table($lineTable)->where('company_id',$companyId)->where($fk,$invoiceId);
            // Source is already locked. Use a current line read for the union,
            // not a potentially older Support transaction's RR snapshot.
            if($linkedShipments->isNotEmpty())$invoiceItemQuery->lockForUpdate();
            $invoiceItems=$invoiceItemQuery->pluck('item_id');
            if($linkedShipments->isNotEmpty())app(ShipmentInventoryLockScope::class)->prepare($companyId,$linkedShipments->pluck('shipment_id')->map(fn($id)=>(int)$id)->all(),$invoiceItems);
            else InventoryPostingLocks::lockItems($companyId,$invoiceItems);

            $lines=DB::table($lineTable.' as l')
                ->join('items as i','i.id','=','l.item_id')
                ->where('l.company_id',$companyId)->where('l.'.$fk,$invoiceId)
                ->select('l.*','i.item_name','i.allow_negative_stock')
                ->orderBy('l.id')->lockForUpdate()->get();
            if($lines->isEmpty())throw new \RuntimeException('الفاتورة لا تحتوي أصنافاً.');
            if($lines->contains(fn($line)=>\App\Services\Uom\OperationalDocument::isBase($line))&&DB::transactionLevel()!==1&&$ownedScope===null&&!$uomOwned)throw new \RuntimeException('UOM_TRUSTED_POSTING_ROOT_REQUIRED');

            // Accounting preflight BEFORE physical stock movement. A missing item/group/category account blocks POST cleanly.
            foreach($lines as $line){
                $itemId=(int)$line->item_id;$service=strtoupper((string)($line->item_type_snapshot??'STOCK'))==='SERVICE'||(int)($line->track_inventory_snapshot??1)!==1;
                if($sale){$this->itemAccounts->sales($companyId,$itemId);if(!$service){$this->itemAccounts->inventory($companyId,$itemId);$this->itemAccounts->cogs($companyId,$itemId);}}
                else{if($service)$this->itemAccounts->purchaseExpense($companyId,$itemId);else$this->itemAccounts->inventory($companyId,$itemId);}
            }
            if($sale)$this->itemAccounts->receivable($companyId,(int)$inv->customer_id);else$this->itemAccounts->payable($companyId,(int)$inv->supplier_id);

            // Linked PURCHASE only: current cost rows, after existing H/I/R,
            // before any physical stock/journal/PAY write. No allocator enabled.
            if(!$sale&&$linkedShipments->isNotEmpty()&&config('shipment_cost_read.enabled'))app(ShipmentPurchaseCostRead::class)->prepare($companyId,$linkedShipments->pluck('shipment_id')->all());
            if(!$sale&&$linkedShipments->isNotEmpty()&&config('shipment_allocation.enabled')) {
                foreach($linkedShipments->pluck('shipment_id')->unique()->sort() as $sid) {
                    app(ShipmentAllocationLedger::class)->prepare($companyId,(int)$sid,$lines);
                }
            }

            // Inventory first inside the same DB transaction. Any shortage/error rolls everything back.
            if($sale)$this->postSaleInventory($companyId,$inv,$lines,$userId);
            else$this->postPurchaseInventory($companyId,$inv,$lines,$userId);

            $journalId=$sale
                ?$this->accounting->postSale($companyId,$invoiceId,$userId,$isolatedCapture)
                :$this->accounting->postPurchase($companyId,$invoiceId,$userId,$isolatedCapture);
            if(!$sale&&$lines->contains(fn($line)=>\App\Services\Uom\OperationalDocument::isBase($line)))\App\Services\Uom\BaseInvoiceHeaderCost::markPosted($companyId,$inv,$journalId);

            DB::table($table)->where('id',$invoiceId)->update([
                'document_status'=>'POSTED','posted_at'=>now(),'posted_by'=>$userId,'updated_at'=>now()
            ]);

            $shipmentIds=DB::table('invoice_shipment_links')
                ->where('company_id',$companyId)->where('invoice_type',$mode)->where('invoice_id',$invoiceId)
                ->pluck('shipment_id')->map(fn($x)=>(int)$x)->all();

            foreach($shipmentIds as$shipmentId){
                DB::table('shipments')->where('company_id',$companyId)->where('id',$shipmentId)->update([
                    'commercial_status'=>'INVOICED','status'=>'APPROVED','invoiced_at'=>now(),'invoiced_by'=>$userId,'updated_at'=>now()
                ]);
                if(!$sale)$this->shipmentCosts->postPendingForShipment($companyId,$shipmentId,$userId);
            }

            return [
                'id'=>$invoiceId,'journal_entry_id'=>$journalId,'shipments'=>$shipmentIds,
                'message'=>$sale
                    ?'تم ترحيل فاتورة البيع والمخزون والمحاسبة.'
                    :'تم ترحيل فاتورة الشراء والمخزون والمحاسبة وتكاليف الشحنات.',
            ];
        }); } catch(\Throwable $failure) {
            if($ownedScope!==null)throw app(ShipmentTransactionOwner::class)->markRollbackOnly($failure);
            if($uomOwned)throw app(\App\Services\Uom\OperationalTransactionOwner::class)->markRollbackOnly($failure);
            throw $failure;
        } finally {
            if($restoreIsolation!==null)$restoreIsolation();
        }
    }

    public function void(string $mode,int $companyId,int $invoiceId,int $userId,string $reason,?int $branchFilter=null): array
    {
        $mode=strtoupper($mode);
        $sale=$mode==='SALE';
        $table=$sale?'sales_invoices':'purchase_invoices';
        $ownedScope=$sale?null:app(ShipmentTransactionOwner::class)->scope($companyId,$invoiceId,$userId,'PURCHASE_VOID');
        if(!$sale&&$ownedScope===null&&DB::transactionLevel()>0)throw new \RuntimeException('PURCHASE_VOID_OUTERMOST_REQUIRED');
        $uomOwned=$sale&&app(\App\Services\Uom\OperationalTransactionOwner::class)->owns($companyId,$invoiceId,$userId,'SALE_VOID');
        try { return DB::transaction(function()use($mode,$sale,$companyId,$invoiceId,$userId,$reason,$branchFilter,$table,$uomOwned){
            if(mb_strlen(trim($reason))<5)throw new \RuntimeException('سبب العكس مطلوب ويجب أن يكون واضحاً.');

            $inv=DB::table($table)->where('company_id',$companyId)->where('id',$invoiceId)->lockForUpdate()->first();
            if(!$inv)throw new \RuntimeException('الفاتورة غير موجودة.');
            if($branchFilter!==null&&(int)$inv->branch_id!==$branchFilter)throw new \RuntimeException('الفاتورة خارج نطاق فرعك.');
            if(($inv->document_status??'')!=='POSTED'||!$inv->journal_entry_id)throw new \RuntimeException('يمكن عكس الفاتورة المرحلة فقط.');
            if($inv->voided_at)throw new \RuntimeException('تم عكس الفاتورة مسبقًا.');

            $shipmentIds=DB::table('invoice_shipment_links')
                ->where('company_id',$companyId)->where('invoice_type',$mode)->where('invoice_id',$invoiceId)
                ->pluck('shipment_id')->map(fn($x)=>(int)$x)->all();

            if($sale){
                $saleLines=DB::table('sales_invoice_lines')->where('company_id',$companyId)->where('sales_invoice_id',$invoiceId)->get();
                if($saleLines->contains(fn($line)=>\App\Services\Uom\OperationalDocument::isBase($line))){
                    if(DB::transactionLevel()!==1&&!$uomOwned)throw new \RuntimeException('UOM_TRUSTED_VOID_ROOT_REQUIRED');
                    InventoryPostingLocks::lockItems($companyId,$saleLines->pluck('item_id'));
                    if(DB::table('commercial_returns')->where('company_id',$companyId)->where('return_type','SALES_RETURN')->where('source_invoice_id',$invoiceId)->where('document_status','POSTED')->exists())throw new \RuntimeException('UOM_VOID_ACTIVE_RETURN_EXISTS');
                }
                $this->reverseSaleInventory($companyId,$invoiceId,$userId);
            }else{
                app(ShipmentInventoryLockScope::class)->prepare($companyId,$shipmentIds,
                    DB::table('purchase_invoice_lines')->where('company_id',$companyId)->where('purchase_invoice_id',$invoiceId)->pluck('item_id'));
                $this->assertPurchaseCanVoid($companyId,$invoiceId);
                // Shipment cost journals belong to the purchase lifecycle and must be reversed too.
                foreach($shipmentIds as$shipmentId){
                    $costs=DB::table('shipment_costs')
                        ->where('company_id',$companyId)->where('shipment_id',$shipmentId)
                        ->where('cost_status','POSTED')->whereNotNull('journal_entry_id')->lockForUpdate()->get();
                    foreach($costs as$cost){
                        $entry=DB::table('journal_entries')->where('company_id',$companyId)->where('id',$cost->journal_entry_id)->first();
                        if($entry&&!$entry->reversed_at){
                            $this->journals->reverse($companyId,(int)$cost->journal_entry_id,[
                                'reason'=>'عكس تكلفة مرتبطة بفاتورة '.$inv->invoice_number.' — '.$reason,
                                'entry_date'=>date('Y-m-d'),'source_type'=>'SHIPMENT_COST_REVERSAL','created_by'=>$userId,
                            ]);
                        }
                        DB::table('shipment_costs')->where('id',$cost->id)->update([
                            'cost_status'=>'VOID','updated_at'=>now()
                        ]);
                        // Keep the old posted cost as immutable history and create a clean DRAFT copy for a corrected/reissued purchase invoice.
                        $copy=(array)$cost;
                        unset($copy['id']);
                        $copy['cost_status']='DRAFT';$copy['journal_entry_id']=null;$copy['voucher_id']=null;$copy['posted_at']=null;$copy['posted_by']=null;$copy['distributed']=0;
                        $copy['recreated_from_cost_id']=$cost->id;$copy['created_by']=$userId;$copy['created_at']=now();$copy['updated_at']=now();
                        DB::table('shipment_costs')->insert($copy);
                    }
                }
            }

            $reversalId=$this->journals->reverse($companyId,(int)$inv->journal_entry_id,[
                'reason'=>$reason,'entry_date'=>date('Y-m-d'),
                'source_type'=>$sale?'SALE_REVERSAL':'PURCHASE_REVERSAL','created_by'=>$userId,
            ]);

            if(!$sale){
                $this->reversePurchaseInventory($companyId,$invoiceId,$userId);
                foreach($shipmentIds as$shipmentId){
                    DB::table('shipment_items')->where('company_id',$companyId)->where('shipment_id',$shipmentId)->update([
                        'inventory_lot_id'=>null,'purchase_line_id'=>null,'inventory_created'=>0,'inventory_qty_kg'=>0,
                        'allocated_cost'=>0,'distributed_cost'=>0,'final_cost'=>0,'final_unit_cost_per_kg'=>0,'average_cost'=>0,'updated_at'=>now(),
                    ]);
                    DB::table('shipments')->where('company_id',$companyId)->where('id',$shipmentId)->update(['distributed_cost'=>0,'costing_status'=>'PENDING','updated_at'=>now()]);
                }
            }

            DB::table($table)->where('id',$invoiceId)->update([
                'document_status'=>'VOID','voided_at'=>now(),'voided_by'=>$userId,'void_reason'=>$reason,'updated_at'=>now()
            ]);
            foreach($shipmentIds as$shipmentId){
                DB::table('shipments')->where('company_id',$companyId)->where('id',$shipmentId)->update([
                    'commercial_status'=>'READY','status'=>'READY','invoiced_at'=>null,'invoiced_by'=>null,'updated_at'=>now()
                ]);
            }

            return ['id'=>$invoiceId,'reversal_journal_id'=>$reversalId,'message'=>'تم عكس الفاتورة مع الحفاظ على كامل الأثر التاريخي.'];
        }); } catch(\Throwable $failure) {
            if($ownedScope!==null)throw app(ShipmentTransactionOwner::class)->markRollbackOnly($failure);
            if($uomOwned)throw app(\App\Services\Uom\OperationalTransactionOwner::class)->markRollbackOnly($failure);
            throw $failure;
        }
    }

    public function deleteDraft(string $mode,int $companyId,int $id,?int $branchFilter=null): void
    {
        $sale=strtoupper($mode)==='SALE';
        $table=$sale?'sales_invoices':'purchase_invoices';
        $lineTable=$sale?'sales_invoice_lines':'purchase_invoice_lines';
        $fk=$sale?'sales_invoice_id':'purchase_invoice_id';

        $inv=DB::table($table)->where('company_id',$companyId)->where('id',$id)->first();
        if(!$inv)throw new \RuntimeException('الفاتورة غير موجودة.');
        if($branchFilter!==null&&(int)$inv->branch_id!==$branchFilter)throw new \RuntimeException('الفاتورة خارج نطاق فرعك.');
        if(($inv->document_status??'DRAFT')!=='DRAFT'||$inv->journal_entry_id)throw new \RuntimeException('يمكن حذف المسودة فقط.');

        DB::transaction(function()use($companyId,$id,$table,$lineTable,$fk,$mode){
            DB::table($lineTable)->where('company_id',$companyId)->where($fk,$id)->delete();
            DB::table('invoice_shipment_links')->where('company_id',$companyId)->where('invoice_type',strtoupper($mode))->where('invoice_id',$id)->delete();
            DB::table($table)->where('company_id',$companyId)->where('id',$id)->delete();
        });
    }

    private function shipmentLines(string $mode,int $companyId,int $branchId,int $partyId,array $ids,?int $editingInvoiceId=null): array
    {
        $out=[];
        $type=$mode==='SALE'?'SALE':'PURCHASE';
        foreach($ids as$shipmentId){
            $shipment=DB::table('shipments')->where('company_id',$companyId)->where('id',$shipmentId)->lockForUpdate()->first();
            if(!$shipment)throw new \RuntimeException('إحدى الشحنات المختارة غير موجودة.');
            if((int)$shipment->branch_id!==$branchId)throw new \RuntimeException('لا يمكن جمع شحنات من فروع مختلفة في فاتورة فرع واحدة.');
            if(strtoupper((string)$shipment->shipment_type)!==$type)throw new \RuntimeException('نوع إحدى الشحنات لا يطابق نوع الفاتورة.');
            if(($shipment->commercial_status??'DRAFT')!=='READY')throw new \RuntimeException('الشحنة '.$shipment->shipment_number.' ليست جاهزة للفوترة.');
            if($mode==='SALE'&&(int)$shipment->customer_id!==$partyId)throw new \RuntimeException('كل شحنات البيع يجب أن تخص العميل نفسه.');
            if($mode==='PURCHASE'&&(int)$shipment->supplier_id!==$partyId)throw new \RuntimeException('كل شحنات الشراء يجب أن تخص المورد نفسه.');

            $linkQ=DB::table('invoice_shipment_links')->where('company_id',$companyId)->where('shipment_id',$shipmentId);
            if($editingInvoiceId)$linkQ->where(function($q)use($mode,$editingInvoiceId){
                $q->where('invoice_type','<>',$mode)->orWhere('invoice_id','<>',$editingInvoiceId);
            });
            if($linkQ->exists())throw new \RuntimeException('الشحنة '.$shipment->shipment_number.' مرتبطة بالفعل بفاتورة أخرى.');

            $items=DB::table('shipment_items')->where('company_id',$companyId)->where('shipment_id',$shipmentId)->orderBy('sorting_order')->get();
            foreach($items as$item){
                if(\App\Services\Uom\OperationalDocument::isBase($item)){
                    $out[]=['item_id'=>(int)$item->item_id,'quantity'=>(string)$item->quantity,'unit_code'=>$item->unit_code,'price_unit'=>'UNIT','unit_price'=>(string)$item->uom_price_snapshot,'discount_amount'=>$item->discount_amount,'tax_code_id'=>$item->tax_code_id,'vat_percent'=>$item->vat_percent,'shipment_id'=>$shipmentId,'shipment_item_id'=>(int)$item->id,'notes'=>'من الشحنة '.$shipment->shipment_number];
                    continue;
                }
                $out[]=[
                    'item_id'=>(int)$item->item_id,
                    'qty_kg'=>(float)($item->accepted_qty_kg??$item->qty_kg),
                    'price_unit'=>'KG',
                    'unit_price'=>(float)($item->unit_price_per_kg??((float)$item->unit_price/1000)),
                    'discount_amount'=>(float)($item->discount_amount??0),
                    'tax_code_id'=>$item->tax_code_id,
                    'vat_percent'=>$item->vat_percent,
                    'shipment_id'=>$shipmentId,
                    'shipment_item_id'=>(int)$item->id,
                    'notes'=>'من الشحنة '.$shipment->shipment_number,
                ];
            }
        }
        return $out;
    }

    private function postPurchaseInventory(int $companyId,object $inv,$lines,int $userId): void
    {
        $stockLines=$lines->filter(fn($line)=>(int)$line->track_inventory_snapshot===1&&strtoupper((string)$line->item_type_snapshot)!=='SERVICE');
        $headerBase=round(((float)($inv->transport_cost??0)+(float)($inv->extra_cost??0))*(float)($inv->exchange_rate?:1),3);
        $stockBasis=max(0.001,(float)$stockLines->sum('base_total_before_vat'));
        $headerShares=$stockLines->contains(fn($line)=>\App\Services\Uom\OperationalDocument::isBase($line))?\App\Services\Uom\BaseInvoiceHeaderCost::prepare($companyId,$inv,$stockLines):[];

        foreach($lines as$line){
            if((int)$line->track_inventory_snapshot!==1||strtoupper((string)$line->item_type_snapshot)==='SERVICE')continue;
            $share=(float)$line->base_total_before_vat/$stockBasis;
            $base=round((float)$line->base_total_before_vat+$headerBase*$share,3);
            if($headerShares||\App\Services\Uom\OperationalDocument::isBase($line))$base=bcadd((string)$line->base_total_before_vat,$headerShares[$line->id]??'0',3);
            if(\App\Services\Uom\OperationalDocument::isBase($line)){
                $shipmentSource=\App\Services\Uom\ShipmentBaseLine::invoiceSource($companyId,(int)$inv->branch_id,(int)$inv->id,$line,'PURCHASE');
                if($shipmentSource&&(!config('shipment_allocation.enabled')||!config('shipment_cost_read.enabled')||!DB::getSchemaBuilder()->hasTable('uom_lot_capitalization_events')))throw new \RuntimeException('UOM_SHIPMENT_L3_REQUIRED');
                $baseValue=is_string($base)?$base:number_format($base,3,'.','');
                $lotId=$this->lots->createInboundLot([
                    'company_id'=>$companyId,'branch_id'=>(int)$inv->branch_id,'item_id'=>(int)$line->item_id,
                    'shipment_id'=>$line->shipment_id,'shipment_item_id'=>$line->shipment_item_id,'car_id'=>$line->car_id,
                    ...\App\Services\Uom\OperationalDocument::columns($line,(string)$line->base_quantity),
                    'purchase_invoice_id'=>$inv->id,'purchase_invoice_line_id'=>$line->id,'base_cost'=>$baseValue,
                    'source_type'=>'PURCHASE','source_id'=>$inv->id,'received_at'=>$inv->invoice_date.' 00:00:00','created_by'=>$userId,
                ]);
                \App\Services\Uom\BaseInventory::stock($line,$companyId,(int)$inv->branch_id,'IN',(string)$line->base_quantity,$baseValue,'PURCHASE',(int)$inv->id,$userId,$inv->invoice_date,$lotId);
                if($shipmentSource)DB::table('shipment_items')->where('company_id',$companyId)->where('id',$shipmentSource->id)->update(['inventory_lot_id'=>$lotId,'purchase_line_id'=>$line->id,'inventory_created'=>1,'base_cost'=>$line->base_total_before_vat,'final_cost'=>number_format($base,3,'.',''),'updated_at'=>now()]);
                continue;
            }
            $kg=round((float)$line->qty_kg,3);
            if($kg<=0)throw new \RuntimeException('كمية المخزون في فاتورة الشراء غير صالحة.');

            $lotId=$this->lots->createInboundLot([
                'company_id'=>$companyId,'branch_id'=>(int)$inv->branch_id,'item_id'=>(int)$line->item_id,
                'car_id'=>$line->car_id,'shipment_id'=>$line->shipment_id,'shipment_item_id'=>$line->shipment_item_id,
                'purchase_invoice_id'=>$inv->id,'purchase_invoice_line_id'=>$line->id,'qty_kg'=>$kg,
                'base_cost'=>$base,'source_type'=>'PURCHASE','source_id'=>$inv->id,
                'received_at'=>$inv->invoice_date.' 00:00:00','notes'=>'دفعة من فاتورة '.$inv->invoice_number,'created_by'=>$userId,
            ]);

            DB::table('stock_movements')->insert([
                'company_id'=>$companyId,'branch_id'=>$inv->branch_id,'item_id'=>$line->item_id,'car_id'=>$line->car_id,
                'inventory_lot_id'=>$lotId,'movement_type'=>'IN','source_type'=>'PURCHASE','source_id'=>$inv->id,
                'movement_date'=>$inv->invoice_date,'qty'=>round($kg/1000,6),'qty_kg'=>$kg,
                'unit_cost'=>round(($base/$kg)*1000,3),'unit_cost_per_kg'=>round($base/$kg,6),'total_cost'=>$base,
                'notes'=>'فاتورة شراء','created_by'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);

            if($line->shipment_item_id){
                DB::table('shipment_items')->where('company_id',$companyId)->where('id',$line->shipment_item_id)->update([
                    'inventory_lot_id'=>$lotId,'purchase_line_id'=>$line->id,'inventory_created'=>1,
                    'inventory_qty_kg'=>$kg,'base_cost'=>(float)$line->base_total_before_vat,
                    'updated_at'=>now(),
                ]);
            }
        }
    }

    private function postSaleInventory(int $companyId,object $inv,$lines,int $userId): void
    {
        foreach($lines as$line){
            if((int)$line->track_inventory_snapshot!==1||strtoupper((string)$line->item_type_snapshot)==='SERVICE')continue;
            if(\App\Services\Uom\OperationalDocument::isBase($line)){
                \App\Services\Uom\ShipmentBaseLine::invoiceSource($companyId,(int)$inv->branch_id,(int)$inv->id,$line,'SALE');
                $consumption=\App\Services\Uom\BaseInventory::consume($companyId,(int)$inv->branch_id,(int)$line->item_id,(string)$line->base_quantity,'SALE',(int)$inv->id,$userId);
                foreach($consumption['allocations'] as $source){
                    DB::table('sales_line_lot_sources')->insert(['company_id'=>$companyId,'branch_id'=>$inv->branch_id,'sales_invoice_line_id'=>$line->id,...$source,'created_at'=>now(),'updated_at'=>now()]);
                    \App\Services\Uom\BaseInventory::stock($line,$companyId,(int)$inv->branch_id,'OUT',$source['base_quantity'],$source['total_cost'],'SALE',(int)$inv->id,$userId,$inv->invoice_date,(int)$source['inventory_lot_id']);
                }
                continue;
            }
            $kg=round((float)$line->qty_kg,3);
            // consumeFifo deliberately refuses negative stock. Physical items cannot be sold without stock.
            $consumption=$this->lots->consumeFifo($companyId,(int)$inv->branch_id,(int)$line->item_id,$kg,'SALE',(int)$inv->id,null,$userId);

            foreach($consumption['allocations']as$source){
                DB::table('sales_line_lot_sources')->insert([
                    'company_id'=>$companyId,'branch_id'=>$inv->branch_id,'sales_invoice_line_id'=>$line->id,
                    'inventory_lot_id'=>$source['inventory_lot_id'],'shipment_id'=>$source['shipment_id'],
                    'shipment_item_id'=>$source['shipment_item_id'],'qty_kg'=>$source['qty_kg'],
                    'unit_cost_per_kg'=>$source['unit_cost_per_kg'],'total_cost'=>$source['total_cost'],
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
                if($source['shipment_item_id']){
                    $si=DB::table('shipment_items')->where('company_id',$companyId)->where('id',$source['shipment_item_id'])->first();
                    if($si)DB::table('shipment_items')->where('id',$si->id)->update([
                        'remaining_qty_kg'=>max(0,round((float)$si->remaining_qty_kg-(float)$source['qty_kg'],3)),
                        'sold_qty_kg'=>round((float)$si->sold_qty_kg+(float)$source['qty_kg'],3),
                        'remaining_qty'=>max(0,round((float)$si->remaining_qty-(float)$source['qty_kg']/1000,6)),
                        'sold_qty'=>round((float)$si->sold_qty+(float)$source['qty_kg']/1000,6),
                        'updated_at'=>now(),
                    ]);
                }
            }

            DB::table('stock_movements')->insert([
                'company_id'=>$companyId,'branch_id'=>$inv->branch_id,'item_id'=>$line->item_id,'car_id'=>$line->car_id,
                'movement_type'=>'OUT','source_type'=>'SALE','source_id'=>$inv->id,'movement_date'=>$inv->invoice_date,
                'qty'=>round($kg/1000,6),'qty_kg'=>$kg,'unit_cost'=>round($consumption['unit_cost_per_kg']*1000,3),
                'unit_cost_per_kg'=>$consumption['unit_cost_per_kg'],'total_cost'=>$consumption['total_cost'],
                'notes'=>'فاتورة بيع - FIFO','created_by'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
    }

    private function assertPurchaseCanVoid(int $companyId,int $invoiceId): void
    {
        $lots=DB::table('inventory_lots')->where('company_id',$companyId)->where('purchase_invoice_id',$invoiceId)->get();
        foreach($lots as$lot){
            if(\App\Services\Uom\OperationalDocument::isBase($lot)){
                if(bccomp($lot->remaining_base_qty,$lot->received_base_qty,6)!==0)throw new \RuntimeException('UOM_PURCHASE_ALREADY_ISSUED');
                continue;
            }
            if(round((float)$lot->qty_remaining_kg,3)<round((float)$lot->qty_received_kg,3)){
                throw new \RuntimeException('لا يمكن عكس فاتورة شراء تم صرف جزء من دفعاتها. استخدم مردود شراء/تسوية معتمدة.');
            }
        }
    }

    private function reversePurchaseInventory(int $companyId,int $invoiceId,int $userId): void
    {
        $lots=DB::table('inventory_lots')->where('company_id',$companyId)->where('purchase_invoice_id',$invoiceId)->lockForUpdate()->get();
        foreach($lots as$lot){
            if(\App\Services\Uom\OperationalDocument::isBase($lot)){
                if(bccomp($lot->remaining_base_qty,'0',6)>0){
                    $value=\App\Services\Uom\BaseInventory::remove($lot,$lot->remaining_base_qty);
                    \App\Services\Uom\BaseInventory::movement($lot,'OUT',$lot->remaining_base_qty,$value,'PURCHASE_REVERSAL',$invoiceId,$userId,date('Y-m-d'));
                    \App\Services\Uom\BaseInventory::stock($lot,$companyId,(int)$lot->branch_id,'OUT',$lot->remaining_base_qty,$value,'PURCHASE_REVERSAL',$invoiceId,$userId,date('Y-m-d'),(int)$lot->id);
                }
                continue;
            }
            $qty=round((float)$lot->qty_remaining_kg,3);if($qty<=0)continue;$cost=round($qty*(float)$lot->unit_cost_per_kg,3);
            if(config('inventory_value.enabled'))$cost=(float)InventoryLotValue::issue($lot,number_format($qty,3,'.',''));
            DB::table('inventory_lot_movements')->insert([
                'company_id'=>$companyId,'branch_id'=>$lot->branch_id,'inventory_lot_id'=>$lot->id,'item_id'=>$lot->item_id,
                'movement_type'=>'OUT','source_type'=>'PURCHASE_REVERSAL','source_id'=>$invoiceId,'movement_at'=>now(),
                'qty_kg'=>$qty,'unit_cost_per_kg'=>$lot->unit_cost_per_kg,'total_cost'=>$cost,
                'notes'=>'عكس فاتورة شراء','created_by'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('stock_movements')->insert([
                'company_id'=>$companyId,'branch_id'=>$lot->branch_id,'item_id'=>$lot->item_id,'inventory_lot_id'=>$lot->id,
                'movement_type'=>'OUT','source_type'=>'PURCHASE_REVERSAL','source_id'=>$invoiceId,'movement_date'=>date('Y-m-d'),
                'qty'=>round($qty/1000,6),'qty_kg'=>$qty,'unit_cost'=>round((float)$lot->unit_cost_per_kg*1000,3),'unit_cost_per_kg'=>$lot->unit_cost_per_kg,'total_cost'=>$cost,
                'notes'=>'عكس فاتورة شراء','created_by'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('inventory_lots')->where('id',$lot->id)->update(['qty_remaining_kg'=>0,'lot_status'=>'CLOSED','updated_at'=>now()]);
        }
        DB::table('stock_movements')->where('company_id',$companyId)->where('source_type','PURCHASE')->where('source_id',$invoiceId)->update([
            'notes'=>DB::raw("CONCAT(COALESCE(notes,''),' [تم عكس الفاتورة]')"),'updated_at'=>now()
        ]);
    }

    private function reverseSaleInventory(int $companyId,int $invoiceId,int $userId): void
    {
        $lineIds=DB::table('sales_invoice_lines')->where('company_id',$companyId)->where('sales_invoice_id',$invoiceId)->pluck('id');
        $sources=DB::table('sales_line_lot_sources')->where('company_id',$companyId)->whereIn('sales_invoice_line_id',$lineIds)->lockForUpdate()->get();
        foreach($sources as$source){
            $lot=DB::table('inventory_lots')->where('company_id',$companyId)->where('id',$source->inventory_lot_id)->lockForUpdate()->first();
            if(!$lot)continue;
            if(\App\Services\Uom\OperationalDocument::isBase($source)){
                \App\Services\Uom\BaseInventory::restore($lot,$source->base_quantity,$source->total_cost);
                \App\Services\Uom\BaseInventory::movement($lot,'IN',$source->base_quantity,$source->total_cost,'SALE_REVERSAL',$invoiceId,$userId,date('Y-m-d'));
                \App\Services\Uom\BaseInventory::stock($lot,$companyId,(int)$lot->branch_id,'IN',$source->base_quantity,$source->total_cost,'SALE_REVERSAL',$invoiceId,$userId,date('Y-m-d'),(int)$lot->id);
                continue;
            }
            InventoryLotValue::restore($lot,(string)$source->qty_kg,(string)$source->total_cost);
            DB::table('inventory_lots')->where('id',$lot->id)->update([
                'qty_remaining_kg'=>round((float)$lot->qty_remaining_kg+(float)$source->qty_kg,3),
                'qty_sold_kg'=>max(0,round((float)$lot->qty_sold_kg-(float)$source->qty_kg,3)),
                'lot_status'=>'OPEN','updated_at'=>now(),
            ]);
            DB::table('inventory_lot_movements')->insert([
                'company_id'=>$companyId,'branch_id'=>$source->branch_id,'inventory_lot_id'=>$lot->id,'item_id'=>$lot->item_id,
                'movement_type'=>'IN','source_type'=>'SALE_REVERSAL','source_id'=>$invoiceId,'movement_at'=>now(),
                'qty_kg'=>$source->qty_kg,'unit_cost_per_kg'=>$source->unit_cost_per_kg,'total_cost'=>$source->total_cost,
                'notes'=>'عكس فاتورة بيع','created_by'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            DB::table('stock_movements')->insert([
                'company_id'=>$companyId,'branch_id'=>$source->branch_id,'item_id'=>$lot->item_id,'inventory_lot_id'=>$lot->id,
                'movement_type'=>'IN','source_type'=>'SALE_REVERSAL','source_id'=>$invoiceId,'movement_date'=>date('Y-m-d'),
                'qty'=>round((float)$source->qty_kg/1000,6),'qty_kg'=>$source->qty_kg,'unit_cost'=>round((float)$source->unit_cost_per_kg*1000,3),'unit_cost_per_kg'=>$source->unit_cost_per_kg,'total_cost'=>$source->total_cost,
                'notes'=>'عكس فاتورة بيع','created_by'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            if($source->shipment_item_id){
                $si=DB::table('shipment_items')->where('company_id',$companyId)->where('id',$source->shipment_item_id)->first();
                if($si)DB::table('shipment_items')->where('id',$si->id)->update([
                    'remaining_qty_kg'=>round((float)$si->remaining_qty_kg+(float)$source->qty_kg,3),
                    'sold_qty_kg'=>max(0,round((float)$si->sold_qty_kg-(float)$source->qty_kg,3)),
                    'remaining_qty'=>round((float)$si->remaining_qty+(float)$source->qty_kg/1000,6),
                    'sold_qty'=>max(0,round((float)$si->sold_qty-(float)$source->qty_kg/1000,6)),
                    'updated_at'=>now(),
                ]);
            }
        }
        DB::table('stock_movements')->where('company_id',$companyId)->where('source_type','SALE')->where('source_id',$invoiceId)->update([
            'notes'=>DB::raw("CONCAT(COALESCE(notes,''),' [تم عكس الفاتورة]')"),'updated_at'=>now()
        ]);
    }

}
