<?php

namespace App\Services;

use App\Domain\Accounting\Services\JournalService;
use App\Services\Accounting\PostingSupport;
use App\Services\Accounting\ItemAccountingResolver;
use Illuminate\Support\Facades\DB;

class InventoryOperationService
{
    private const PROCESS_TYPES = [
        'CONVERSION','SORTING','CLEANING','STRIPPING','CUTTING','DISASSEMBLY',
        'RECLASSIFICATION','MIXING','ASSEMBLY','SCRAP',
    ];

    public function __construct(
        private InventoryLotService $lots,
        private JournalService $journals,
        private PostingSupport $posting,
        private FinancialAccountService $money,
        private ItemAccountingResolver $itemAccounts,
        private SulbDocumentSequenceService $sequences
    ) {}

    public function create(array $data): int
    {
        return DB::transaction(function () use ($data) {
            $companyId = (int) $data['company_id'];
            $type = strtoupper(trim((string) $data['operation_type']));
            $fromBranchId = (int) $data['from_branch_id'];
            $toBranchId = isset($data['to_branch_id']) && (int) $data['to_branch_id'] > 0
                ? (int) $data['to_branch_id']
                : null;

            if (!in_array($type, array_merge(['TRANSFER'], self::PROCESS_TYPES), true)) {
                throw new \RuntimeException('نوع العملية المخزنية غير مدعوم.');
            }

            $this->assertBranch($companyId, $fromBranchId);

            if ($type === 'TRANSFER') {
                if (!$toBranchId) {
                    throw new \RuntimeException('اختر الفرع المستلم للتحويل.');
                }
                $this->assertBranch($companyId, $toBranchId);
                if ($toBranchId === $fromBranchId) {
                    throw new \RuntimeException('فرع المصدر والوجهة يجب أن يكونا مختلفين.');
                }
            } else {
                $toBranchId = $fromBranchId;
            }

            $fromLines = array_values(array_filter(
                $data['from_lines'] ?? [],
                fn ($r) => (int) ($r['item_id'] ?? 0) > 0 && (float) ($r['qty_kg'] ?? 0) > 0
            ));
            $toLines = array_values(array_filter(
                $data['to_lines'] ?? [],
                fn ($r) => (int) ($r['item_id'] ?? 0) > 0 && (float) ($r['qty_kg'] ?? 0) > 0
            ));

            if (!$fromLines) {
                throw new \RuntimeException('أضف صنف مصدر واحدًا على الأقل.');
            }

            if ($type !== 'TRANSFER' && $type !== 'SCRAP' && !$toLines) {
                throw new \RuntimeException('أضف صنف ناتج واحدًا على الأقل.');
            }

            if ($type === 'TRANSFER' && $toLines) {
                throw new \RuntimeException('التحويل بين الفروع لا يحتاج أسطر ناتجة؛ النظام ينشئها تلقائيًا.');
            }

            foreach ($fromLines as $i => $line) {
                $this->assertItem($companyId, (int) $line['item_id'], $i + 1);
            }
            foreach ($toLines as $i => $line) {
                $this->assertItem($companyId, (int) $line['item_id'], $i + 1);
            }

            $inputKg = round(array_sum(array_map(fn ($r) => (float) $r['qty_kg'], $fromLines)), 3);
            $outputKg = $type === 'TRANSFER'
                ? $inputKg
                : round(array_sum(array_map(fn ($r) => (float) $r['qty_kg'], $toLines)), 3);
            $loss = max(0, round($inputKg - $outputKg, 3));
            $gain = max(0, round($outputKg - $inputKg, 3));
            $reason = trim((string) ($data['loss_gain_reason'] ?? ''));

            if ($type !== 'TRANSFER' && abs($inputKg - $outputKg) > 0.500 && mb_strlen($reason) < 3) {
                throw new \RuntimeException('يوجد فرق وزن أكبر من 0.5 كجم؛ اكتب سبب الفاقد أو الزيادة.');
            }

            $number = $data['operation_number'] ?? $this->sequences->next($companyId,$fromBranchId,'INVENTORY_OPERATION',(string)$data['operation_date'],'IO');

            $operationId = DB::table('inventory_operations')->insertGetId([
                'company_id' => $companyId,
                'branch_id' => $fromBranchId,
                'from_branch_id' => $fromBranchId,
                'to_branch_id' => $toBranchId,
                'operation_number' => $number,
                'operation_type' => $type,
                'allocation_method' => strtoupper((string) ($data['allocation_method'] ?? 'RELATIVE_VALUE')),
                'operation_date' => $data['operation_date'],
                'input_weight_kg' => $inputKg,
                'output_weight_kg' => $outputKg,
                'loss_qty_kg' => $loss,
                'gain_qty_kg' => $gain,
                'loss_gain_reason' => $reason ?: null,
                'status' => 'DRAFT',
                'notes' => $data['notes'] ?? null,
                'created_by' => $data['created_by'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($fromLines as $line) {
                $this->insertLine($companyId, $operationId, $fromBranchId, 'FROM', $line);
            }
            foreach ($toLines as $line) {
                $this->insertLine($companyId, $operationId, $toBranchId, 'TO', $line);
            }

            $costRows = array_values(array_filter(
                $data['costs'] ?? [],
                fn ($r) => trim((string) ($r['cost_type'] ?? '')) !== '' && (float) ($r['amount'] ?? 0) > 0
            ));
            if ($type === 'TRANSFER' && $costRows) {
                throw new \RuntimeException('تكاليف التشغيل الإضافية مخصصة لعمليات المعالجة. مصاريف النقل بين الفروع تسجل كمصروف/شحنة مستقلة حسب سياسة الشركة.');
            }
            foreach ($costRows as $row) {
                $this->insertOperationCost($companyId, $fromBranchId, $operationId, (string) $data['operation_date'], $row, $data['created_by'] ?? null);
            }

            return $operationId;
        });
    }

    public function approve(int $companyId, int $operationId, int $userId, ?int $scopedBranchId = null): array
    {
        return DB::transaction(function () use ($companyId, $operationId, $userId, $scopedBranchId) {
            $operation = DB::table('inventory_operations')
                ->where('company_id', $companyId)
                ->where('id', $operationId)
                ->lockForUpdate()
                ->first();

            if (!$operation) {
                throw new \RuntimeException('العملية المخزنية غير موجودة.');
            }

            if ($scopedBranchId !== null && (int) $operation->from_branch_id !== $scopedBranchId) {
                throw new \RuntimeException('العملية خارج نطاق فرعك.');
            }

            if ($operation->status !== 'DRAFT') {
                throw new \RuntimeException('لا يمكن ترحيل عملية ليست مسودة.');
            }

            $result = $operation->operation_type === 'TRANSFER'
                ? $this->postTransfer($operation, $userId)
                : $this->postProcessing($operation, $userId);

            DB::table('inventory_operations')->where('id', $operationId)->update([
                'status' => 'POSTED',
                'approved_by' => $userId,
                'approved_at' => now(),
                'posted_by' => $userId,
                'posted_at' => now(),
                'journal_entry_id' => $result['journal_entry_id'] ?? null,
                'updated_at' => now(),
            ]);

            return [
                ...$result,
                'operation_id' => $operationId,
                'operation_number' => $operation->operation_number,
                'status' => 'POSTED',
            ];
        });
    }

    public function deleteDraft(int $companyId, int $operationId, ?int $scopedBranchId = null): void
    {
        DB::transaction(function () use ($companyId, $operationId, $scopedBranchId) {
            $q = DB::table('inventory_operations')
                ->where('company_id', $companyId)
                ->where('id', $operationId);

            if ($scopedBranchId !== null) {
                $q->where('from_branch_id', $scopedBranchId);
            }

            $operation = $q->lockForUpdate()->first();

            if (!$operation) {
                throw new \RuntimeException('العملية غير موجودة.');
            }

            if ($operation->status !== 'DRAFT') {
                throw new \RuntimeException('لا يمكن حذف عملية مرحلة. أنشئ عملية تصحيح مستقلة.');
            }

            DB::table('inventory_operation_costs')
                ->where('company_id', $companyId)
                ->where('operation_id', $operationId)
                ->delete();
            DB::table('inventory_operation_lines')
                ->where('company_id', $companyId)
                ->where('operation_id', $operationId)
                ->delete();
            DB::table('inventory_operations')->where('id', $operationId)->delete();
        });
    }

    public function details(int $companyId, int $operationId, ?int $branchId = null): ?array
    {
        $q = DB::table('inventory_operations as o')
            ->leftJoin('branches as fb', 'fb.id', '=', 'o.from_branch_id')
            ->leftJoin('branches as tb', 'tb.id', '=', 'o.to_branch_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.created_by')
            ->where('o.company_id', $companyId)
            ->where('o.id', $operationId);

        if ($branchId !== null) {
            $q->where('o.from_branch_id', $branchId);
        }

        $operation = $q->select(
            'o.*',
            'fb.branch_name as from_branch_name',
            'tb.branch_name as to_branch_name',
            'u.name as created_by_name'
        )->first();

        if (!$operation) {
            return null;
        }

        $lines = DB::table('inventory_operation_lines as l')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('inventory_lots as il', 'il.id', '=', 'l.input_lot_id')
            ->leftJoin('inventory_lots as ol', 'ol.id', '=', 'l.output_lot_id')
            ->where('l.company_id', $companyId)
            ->where('l.operation_id', $operationId)
            ->select(
                'l.*',
                'i.item_code',
                'i.item_name',
                'il.lot_number as input_lot_number',
                'ol.lot_number as output_lot_number'
            )
            ->orderBy('l.line_type')
            ->orderBy('l.id')
            ->get();

        $links = DB::table('inventory_operation_lot_links as x')
            ->leftJoin('inventory_lots as s', 's.id', '=', 'x.source_lot_id')
            ->leftJoin('inventory_lots as p', 'p.id', '=', 'x.produced_lot_id')
            ->where('x.company_id', $companyId)
            ->where('x.operation_id', $operationId)
            ->select('x.*', 's.lot_number as source_lot_number', 'p.lot_number as produced_lot_number')
            ->orderBy('x.id')
            ->get();

        $costs = DB::table('inventory_operation_costs as c')
            ->leftJoin('financial_accounts as fa','fa.id','=','c.financial_account_id')
            ->where('c.company_id',$companyId)->where('c.operation_id',$operationId)
            ->select('c.*','fa.account_name as financial_account_name')
            ->orderBy('c.id')->get();

        return ['operation' => $operation, 'lines' => $lines, 'lot_links' => $links, 'operation_costs' => $costs];
    }

    private function postTransfer(object $operation, int $userId): array
    {
        $companyId=(int)$operation->company_id;$fromBranch=(int)$operation->from_branch_id;$toBranch=(int)$operation->to_branch_id;
        $fromLines=DB::table('inventory_operation_lines')->where('company_id',$companyId)->where('operation_id',$operation->id)->where('line_type','FROM')->orderBy('id')->lockForUpdate()->get();
        $totalCost=0.0;$totalKg=0.0;$costByItem=[];
        foreach($fromLines as$line){
            $kg=round((float)$line->qty_kg,3);$itemId=(int)$line->item_id;
            $consumption=$this->lots->consumeFifo($companyId,$fromBranch,$itemId,$kg,'TRANSFER',(int)$operation->id,null,$userId);
            DB::table('inventory_operation_lines')->where('id',$line->id)->update(['unit_cost_per_kg'=>$consumption['unit_cost_per_kg'],'unit_cost'=>round($consumption['unit_cost_per_kg']*1000,3),'total_cost'=>$consumption['total_cost'],'updated_at'=>now()]);
            $this->stockMovement($operation,$fromBranch,$itemId,'OUT',$kg,$consumption['total_cost'],$userId,null);
            foreach($consumption['allocations'] as$a){
                $sourceLot=DB::table('inventory_lots')->where('id',$a['inventory_lot_id'])->first();
                $newLotId=$this->lots->createInboundLot(['company_id'=>$companyId,'branch_id'=>$toBranch,'item_id'=>$itemId,'car_id'=>$sourceLot->car_id??null,'shipment_id'=>$sourceLot->shipment_id??null,'shipment_item_id'=>$sourceLot->shipment_item_id??null,'qty_kg'=>$a['qty_kg'],'base_cost'=>$a['total_cost'],'source_type'=>'TRANSFER','source_id'=>$operation->id,'inventory_operation_id'=>$operation->id,'parent_lot_id'=>$a['inventory_lot_id'],'origin_lot_id'=>$sourceLot->origin_lot_id?:$a['inventory_lot_id'],'received_at'=>$operation->operation_date.' 00:00:00','notes'=>'تحويل بين الفروع - '.$operation->operation_number,'created_by'=>$userId]);
                DB::table('inventory_operation_lot_links')->insert(['company_id'=>$companyId,'operation_id'=>$operation->id,'operation_line_id'=>$line->id,'direction'=>'FROM','source_lot_id'=>$a['inventory_lot_id'],'produced_lot_id'=>$newLotId,'item_id'=>$itemId,'branch_id'=>$toBranch,'qty_kg'=>$a['qty_kg'],'unit_cost_per_kg'=>$a['unit_cost_per_kg'],'total_cost'=>$a['total_cost'],'created_at'=>now(),'updated_at'=>now()]);
                $this->stockMovement($operation,$toBranch,$itemId,'IN',$a['qty_kg'],$a['total_cost'],$userId,$newLotId);
            }
            $cost=round((float)$consumption['total_cost'],3);$costByItem[$itemId]=round(($costByItem[$itemId]??0)+$cost,3);$totalCost+=$cost;$totalKg+=$kg;
        }
        $journalId=null;
        if($fromBranch!==$toBranch&&$totalCost>0.0001){
            $dueFrom=$this->settingAccount($companyId,'INTERBRANCH_DUE_FROM_ACCOUNT');$dueTo=$this->settingAccount($companyId,'INTERBRANCH_DUE_TO_ACCOUNT');
            $lines=[['branch_id'=>$fromBranch,'account_id'=>$dueFrom,'counterparty_branch_id'=>$toBranch,'debit'=>round($totalCost,3),'credit'=>0,'description'=>'جاري الفرع المستلم']];
            foreach($costByItem as$itemId=>$cost)$lines[]=['branch_id'=>$fromBranch,'account_id'=>$this->itemAccounts->inventory($companyId,(int)$itemId),'counterparty_branch_id'=>$toBranch,'debit'=>0,'credit'=>$cost,'description'=>'خروج مخزون إلى فرع آخر'];
            foreach($costByItem as$itemId=>$cost)$lines[]=['branch_id'=>$toBranch,'account_id'=>$this->itemAccounts->inventory($companyId,(int)$itemId),'counterparty_branch_id'=>$fromBranch,'debit'=>$cost,'credit'=>0,'description'=>'استلام مخزون من فرع آخر'];
            $lines[]=['branch_id'=>$toBranch,'account_id'=>$dueTo,'counterparty_branch_id'=>$fromBranch,'debit'=>0,'credit'=>round($totalCost,3),'description'=>'جاري الفرع المرسل'];
            $journalId=$this->journals->post(['company_id'=>$companyId,'branch_id'=>null,'allow_company_level'=>true,'entry_date'=>$operation->operation_date,'source_type'=>'INTERBRANCH_INVENTORY_TRANSFER','source_id'=>(int)$operation->id,'description'=>'تحويل مخزون بين الفروع - '.$operation->operation_number,'is_system_generated'=>1,'created_by'=>$userId,'lines'=>$lines]);
        }
        return ['message'=>'تم تحويل المخزون بين الفروع مع المحافظة على تكلفة ومصدر كل دفعة وحساب مخزون كل صنف.','input_weight_kg'=>round($totalKg,3),'output_weight_kg'=>round($totalKg,3),'total_cost'=>round($totalCost,3),'journal_entry_id'=>$journalId];
    }

    private function postProcessing(object $operation, int $userId): array
    {
        $companyId=(int)$operation->company_id;$branchId=(int)$operation->from_branch_id;
        $fromLines=DB::table('inventory_operation_lines')->where('company_id',$companyId)->where('operation_id',$operation->id)->where('line_type','FROM')->orderBy('id')->lockForUpdate()->get();
        $toLines=DB::table('inventory_operation_lines')->where('company_id',$companyId)->where('operation_id',$operation->id)->where('line_type','TO')->orderBy('id')->lockForUpdate()->get();
        $totalFromCost=0.0;$totalFromKg=0.0;$singleOriginLotId=null;$inputAllocationCount=0;$sourceCostByItem=[];
        foreach($fromLines as$line){
            $kg=round((float)$line->qty_kg,3);$itemId=(int)$line->item_id;$consumption=$this->lots->consumeFifo($companyId,$branchId,$itemId,$kg,'INVENTORY_OPERATION',(int)$operation->id,null,$userId);
            DB::table('inventory_operation_lines')->where('id',$line->id)->update(['unit_cost_per_kg'=>$consumption['unit_cost_per_kg'],'unit_cost'=>round($consumption['unit_cost_per_kg']*1000,3),'total_cost'=>$consumption['total_cost'],'updated_at'=>now()]);
            $this->stockMovement($operation,$branchId,$itemId,'OUT',$kg,$consumption['total_cost'],$userId,null);
            foreach($consumption['allocations'] as$a){DB::table('inventory_operation_lot_links')->insert(['company_id'=>$companyId,'operation_id'=>$operation->id,'operation_line_id'=>$line->id,'direction'=>'FROM','source_lot_id'=>$a['inventory_lot_id'],'produced_lot_id'=>null,'item_id'=>$itemId,'branch_id'=>$branchId,'qty_kg'=>$a['qty_kg'],'unit_cost_per_kg'=>$a['unit_cost_per_kg'],'total_cost'=>$a['total_cost'],'created_at'=>now(),'updated_at'=>now()]);$singleOriginLotId=$a['inventory_lot_id'];$inputAllocationCount++;}
            $cost=round((float)$consumption['total_cost'],3);$sourceCostByItem[$itemId]=round(($sourceCostByItem[$itemId]??0)+$cost,3);$totalFromCost+=$cost;$totalFromKg+=$kg;
        }
        $operationCosts=DB::table('inventory_operation_costs')->where('company_id',$companyId)->where('operation_id',$operation->id)->orderBy('id')->lockForUpdate()->get();$overheadCost=round((float)$operationCosts->sum('base_amount'),3);
        if($operation->operation_type==='SCRAP'&&$toLines->isEmpty()){
            $adjustment=$this->posting->setting($companyId,'INVENTORY_ADJUSTMENT_ACCOUNT');$lines=[['account_id'=>$adjustment,'debit'=>round($totalFromCost,3),'credit'=>0,'description'=>'خسارة هالك مخزون']];
            foreach($sourceCostByItem as$itemId=>$cost)$lines[]=['account_id'=>$this->itemAccounts->inventory($companyId,(int)$itemId),'debit'=>0,'credit'=>$cost,'description'=>'إخراج تكلفة الهالك من مخزون الصنف'];
            $journalId=$this->journals->post(['company_id'=>$companyId,'branch_id'=>$branchId,'entry_date'=>$operation->operation_date,'source_type'=>'INVENTORY_SCRAP','source_id'=>$operation->id,'description'=>'هالك مخزون - '.$operation->operation_number,'lines'=>$lines,'is_system_generated'=>1,'created_by'=>$userId]);
            $overheadJournals=$this->postOperationCosts($operation,$operationCosts,[$adjustment=>1.0],$userId);
            return ['message'=>'تم ترحيل الهالك وإخراج تكلفة كل صنف وتكاليف التشغيل محاسبيًا.','input_weight_kg'=>round($totalFromKg,3),'output_weight_kg'=>0,'input_inventory_cost'=>round($totalFromCost,3),'operation_overhead_cost'=>$overheadCost,'total_cost'=>round($totalFromCost+$overheadCost,3),'journal_entry_id'=>$journalId,'cost_journal_entry_ids'=>$overheadJournals];
        }
        if($toLines->isEmpty())throw new \RuntimeException('العملية تحتاج أصنافًا ناتجة.');
        $totalToKg=round((float)$toLines->sum('qty_kg'),3);if($totalToKg<=0)throw new \RuntimeException('إجمالي وزن المخرجات غير صحيح.');
        $totalCostToAllocate=round($totalFromCost+$overheadCost,3);$method=strtoupper((string)($operation->allocation_method?:'RELATIVE_VALUE'));$basis=[];$basisTotal=0.0;
        foreach($toLines as$line){$value=match($method){'WEIGHT'=>(float)$line->qty_kg,'MANUAL_PERCENT'=>(float)($line->allocation_percent??0),'MANUAL_COST'=>(float)($line->total_cost??0),default=>(float)$line->qty_kg*max(0,(float)($line->market_value_per_kg??0)),};if($method==='RELATIVE_VALUE'&&$value<=0){$item=DB::table('items')->where('id',$line->item_id)->first();$value=(float)$line->qty_kg*(max(0,(float)($item->default_sell_price??0))/1000);} $basis[$line->id]=max(0,$value);$basisTotal+=max(0,$value);}
        if($method==='MANUAL_PERCENT'&&abs(round($basisTotal,4)-100)>0.01)throw new \RuntimeException('مجموع نسب توزيع التكلفة يجب أن يساوي 100%.');
        if($method==='MANUAL_COST'&&abs(round($basisTotal,3)-$totalCostToAllocate)>0.05)throw new \RuntimeException('مجموع التكلفة اليدوية للمخرجات يجب أن يساوي تكلفة المدخلات والتشغيل.');
        if($method!=='MANUAL_COST'&&$basisTotal<=0){foreach($toLines as$line){$basis[$line->id]=max(0,(float)$line->qty_kg);$basisTotal+=$basis[$line->id];}}
        if($basisTotal<=0)throw new \RuntimeException('لا يوجد أساس صالح لتوزيع تكلفة العملية.');
        $remaining=$totalCostToAllocate;$lastId=$toLines->last()->id;$outputRows=[];$outputAccountWeights=[];$reclassDebit=[];
        foreach($toLines as$line){
            $share=$method==='MANUAL_COST'?round((float)$line->total_cost,3):($line->id===$lastId?$remaining:round($totalCostToAllocate*($basis[$line->id]/$basisTotal),3));$remaining=round($remaining-$share,3);$kg=round((float)$line->qty_kg,3);$unitKg=$kg>0?round($share/$kg,6):0;$parentLotId=$inputAllocationCount===1?$singleOriginLotId:null;$originLotId=null;if($parentLotId){$p=DB::table('inventory_lots')->where('id',$parentLotId)->first();$originLotId=$p?->origin_lot_id?:$parentLotId;}
            $newLotId=$this->lots->createInboundLot(['company_id'=>$companyId,'branch_id'=>$branchId,'item_id'=>$line->item_id,'qty_kg'=>$kg,'base_cost'=>$share,'source_type'=>'INVENTORY_OPERATION','source_id'=>$operation->id,'inventory_operation_id'=>$operation->id,'parent_lot_id'=>$parentLotId,'origin_lot_id'=>$originLotId,'received_at'=>$operation->operation_date.' 00:00:00','notes'=>$this->typeLabel($operation->operation_type).' - '.$operation->operation_number,'created_by'=>$userId]);
            DB::table('inventory_operation_lines')->where('id',$line->id)->update(['output_lot_id'=>$newLotId,'unit_cost_per_kg'=>$unitKg,'unit_cost'=>round($unitKg*1000,3),'total_cost'=>$share,'updated_at'=>now()]);
            DB::table('inventory_operation_lot_links')->insert(['company_id'=>$companyId,'operation_id'=>$operation->id,'operation_line_id'=>$line->id,'direction'=>'TO','source_lot_id'=>$parentLotId,'produced_lot_id'=>$newLotId,'item_id'=>$line->item_id,'branch_id'=>$branchId,'qty_kg'=>$kg,'unit_cost_per_kg'=>$unitKg,'total_cost'=>$share,'created_at'=>now(),'updated_at'=>now()]);
            $this->stockMovement($operation,$branchId,$line->item_id,'IN',$kg,$share,$userId,$newLotId);
            $acc=$this->itemAccounts->inventory($companyId,(int)$line->item_id);$outputAccountWeights[$acc]=($outputAccountWeights[$acc]??0)+max($share,0.000001);
            $inputPortion=$totalCostToAllocate>0?round($share*($totalFromCost/$totalCostToAllocate),3):0;$reclassDebit[$acc]=round(($reclassDebit[$acc]??0)+$inputPortion,3);
            $outputRows[]=['item_id'=>(int)$line->item_id,'inventory_lot_id'=>$newLotId,'qty_kg'=>$kg,'total_cost'=>$share,'unit_cost_per_kg'=>$unitKg];
        }
        // Reclassify the book value of consumed inventory from source item accounts to produced item accounts.
        $journalId=null;if($totalFromCost>0.0001){$debitSum=round(array_sum($reclassDebit),3);$diff=round($totalFromCost-$debitSum,3);if(abs($diff)>0.0001&&$reclassDebit){$k=array_key_last($reclassDebit);$reclassDebit[$k]=round($reclassDebit[$k]+$diff,3);} $jLines=[];foreach($reclassDebit as$acc=>$cost)$jLines[]=['account_id'=>(int)$acc,'debit'=>$cost,'credit'=>0,'description'=>'إثبات تكلفة مخزون ناتج'];foreach($sourceCostByItem as$itemId=>$cost)$jLines[]=['account_id'=>$this->itemAccounts->inventory($companyId,(int)$itemId),'debit'=>0,'credit'=>$cost,'description'=>'إخراج تكلفة مخزون داخل عملية تشغيل'];$journalId=$this->journals->post(['company_id'=>$companyId,'branch_id'=>$branchId,'entry_date'=>$operation->operation_date,'source_type'=>'INVENTORY_PROCESS_RECLASS','source_id'=>(int)$operation->id,'description'=>$this->typeLabel($operation->operation_type).' - '.$operation->operation_number,'lines'=>$jLines,'is_system_generated'=>1,'created_by'=>$userId]);}
        $overheadJournals=$this->postOperationCosts($operation,$operationCosts,$outputAccountWeights,$userId);
        return ['message'=>'تم ترحيل عملية المعالجة مع نقل تكلفة المدخلات بين حسابات الأصناف وتحميل تكلفة التشغيل على المخرجات.','input_weight_kg'=>round($totalFromKg,3),'output_weight_kg'=>round($totalToKg,3),'loss_qty_kg'=>max(0,round($totalFromKg-$totalToKg,3)),'gain_qty_kg'=>max(0,round($totalToKg-$totalFromKg,3)),'input_inventory_cost'=>round($totalFromCost,3),'operation_overhead_cost'=>$overheadCost,'total_cost'=>$totalCostToAllocate,'outputs'=>$outputRows,'journal_entry_id'=>$journalId,'cost_journal_entry_ids'=>$overheadJournals];
    }

    private function insertLine(int $companyId, int $operationId, int $branchId, string $type, array $line): void
    {
        $kg = round((float) $line['qty_kg'], 3);
        DB::table('inventory_operation_lines')->insert([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'operation_id' => $operationId,
            'line_type' => $type,
            'item_id' => (int) $line['item_id'],
            'car_id' => $line['car_id'] ?? null,
            'shipment_item_id' => $line['shipment_item_id'] ?? null,
            'qty' => round($kg / 1000, 6),
            'qty_kg' => $kg,
            'unit_cost' => round((float) ($line['unit_cost_per_kg'] ?? 0) * 1000, 3),
            'unit_cost_per_kg' => round((float) ($line['unit_cost_per_kg'] ?? 0), 6),
            'total_cost' => round((float) ($line['total_cost'] ?? 0), 3),
            'allocation_percent' => isset($line['allocation_percent']) ? round((float) $line['allocation_percent'], 4) : null,
            'market_value_per_kg' => isset($line['market_value_per_kg']) ? round((float) $line['market_value_per_kg'], 6) : null,
            'notes' => $line['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function stockMovement(object $operation, int $branchId, int $itemId, string $type, float $kg, float $cost, int $userId, ?int $lotId): void
    {
        $unitKg = $kg > 0 ? round($cost / $kg, 6) : 0;
        DB::table('stock_movements')->insert([
            'company_id' => $operation->company_id,
            'branch_id' => $branchId,
            'item_id' => $itemId,
            'inventory_lot_id' => $lotId,
            'movement_type' => $type,
            'source_type' => $operation->operation_type === 'TRANSFER' ? 'TRANSFER' : 'INVENTORY_OPERATION',
            'source_id' => $operation->id,
            'movement_date' => $operation->operation_date,
            'qty' => round($kg / 1000, 6),
            'qty_kg' => round($kg, 3),
            'unit_cost' => round($unitKg * 1000, 3),
            'unit_cost_per_kg' => $unitKg,
            'total_cost' => round($cost, 3),
            'notes' => $this->typeLabel($operation->operation_type) . ' - ' . $operation->operation_number,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertBranch(int $companyId, int $branchId): void
    {
        $ok = DB::table('branches')
            ->where('company_id', $companyId)
            ->where('id', $branchId)
            ->where('is_active', 1)
            ->exists();
        if (!$ok) throw new \RuntimeException('الفرع المحدد غير صالح.');
    }

    private function assertItem(int $companyId, int $itemId, int $lineNo): void
    {
        $ok = DB::table('items')
            ->where('company_id', $companyId)
            ->where('id', $itemId)
            ->where('is_active', 1)
            ->exists();
        if (!$ok) throw new \RuntimeException('الصنف في السطر رقم ' . $lineNo . ' غير صالح.');
    }



    private function typeLabel(string $type): string
    {
        return match ($type) {
            'TRANSFER' => 'تحويل بين الفروع',
            'SORTING' => 'فرز',
            'CLEANING' => 'تنظيف',
            'STRIPPING' => 'خلس / تجريد',
            'CUTTING' => 'تقطيع',
            'DISASSEMBLY' => 'فك',
            'RECLASSIFICATION' => 'إعادة تصنيف',
            'MIXING' => 'خلط',
            'ASSEMBLY' => 'تجميع',
            'SCRAP' => 'هالك',
            default => 'تحويل مخزني',
        };
    }

    private function insertOperationCost(int $companyId, int $branchId, int $operationId, string $date, array $row, ?int $createdBy): void
    {
        $costType = trim((string) ($row['cost_type'] ?? ''));
        $foreign = round((float) ($row['amount'] ?? 0), 3);
        if ($costType === '' || $foreign <= 0) throw new \RuntimeException('بيانات تكلفة التشغيل غير صحيحة.');

        $baseCurrency = $this->money->baseCurrency($companyId);
        $currency = strtoupper(trim((string) ($row['currency_code'] ?? $baseCurrency)));
        $rate = isset($row['exchange_rate']) && $row['exchange_rate'] !== ''
            ? (float) $row['exchange_rate']
            : $this->money->rate($companyId, $currency, $date);
        if ($rate <= 0) throw new \RuntimeException('سعر صرف تكلفة التشغيل غير صالح.');
        $base = round($foreign * $rate, 3);
        $paymentStatus = strtoupper((string) ($row['payment_status'] ?? 'UNPAID')) === 'PAID' ? 'PAID' : 'UNPAID';
        $financialAccountId = isset($row['financial_account_id']) && (int) $row['financial_account_id'] > 0 ? (int) $row['financial_account_id'] : null;
        if ($paymentStatus === 'PAID' && !$financialAccountId) throw new \RuntimeException('اختر الصندوق/البنك لتكلفة التشغيل المدفوعة.');
        if ($financialAccountId) {
            $fa = DB::table('financial_accounts')->where('company_id',$companyId)->where('id',$financialAccountId)->where('is_active',1)->first();
            if (!$fa) throw new \RuntimeException('الحساب المالي لتكلفة التشغيل غير صالح.');
            if (!empty($fa->currency_code) && strtoupper((string)$fa->currency_code) !== $currency) throw new \RuntimeException('عملة تكلفة التشغيل لا تطابق عملة الحساب المالي.');
        }

        DB::table('inventory_operation_costs')->insert([
            'company_id'=>$companyId,'branch_id'=>$branchId,'operation_id'=>$operationId,
            'cost_type'=>$costType,'amount'=>$foreign,'currency_code'=>$currency,'exchange_rate'=>$rate,'base_amount'=>$base,
            'payment_status'=>$paymentStatus,'financial_account_id'=>$financialAccountId,'notes'=>$row['notes']??null,
            'created_by'=>$createdBy,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function postOperationCosts(object $operation, $costs, array $debitWeights, int $userId): array
    {
        $journalIds=[];$baseCurrency=$this->money->baseCurrency((int)$operation->company_id);$weightTotal=array_sum(array_map('floatval',$debitWeights));if($weightTotal<=0)$debitWeights=[$this->posting->setting((int)$operation->company_id,'INVENTORY_ADJUSTMENT_ACCOUNT')=>1.0];$weightTotal=array_sum(array_map('floatval',$debitWeights));
        foreach($costs as$cost){
            if($cost->posted_at||(float)$cost->base_amount<=0)continue;$paid=strtoupper((string)$cost->payment_status)==='PAID';$fa=null;
            if($paid){$fa=DB::table('financial_accounts')->where('company_id',$operation->company_id)->where('id',$cost->financial_account_id)->where('is_active',1)->first();if(!$fa)throw new \RuntimeException('الحساب المالي لتكلفة التشغيل غير صالح.');$creditAccount=(int)$fa->gl_account_id;}else{$creditAccount=$this->posting->setting((int)$operation->company_id,'ACCRUED_EXPENSE_ACCOUNT');}
            $currency=strtoupper((string)($cost->currency_code?:$baseCurrency));$foreign=round((float)$cost->amount,3);$base=round((float)$cost->base_amount,3);$rate=(float)($cost->exchange_rate?:1);
            $lines=[];$remain=$base;$keys=array_keys($debitWeights);$last=end($keys);foreach($debitWeights as$acc=>$weight){$share=(int)$acc===(int)$last?$remain:round($base*((float)$weight/$weightTotal),3);$remain=round($remain-$share,3);if($share>0)$lines[]=['account_id'=>(int)$acc,'debit'=>$share,'credit'=>0,'description'=>'تحميل تكلفة التشغيل على المخزون الناتج','currency_code'=>$currency,'exchange_rate'=>$rate];}
            $credit=['account_id'=>$creditAccount,'debit'=>0,'credit'=>$base,'description'=>'تكلفة تشغيل - '.$cost->cost_type,'currency_code'=>$currency,'exchange_rate'=>$rate,'foreign_debit'=>0,'foreign_credit'=>$currency!==$baseCurrency?$foreign:0];if($fa)$credit['financial_account_id']=(int)$fa->id;$lines[]=$credit;
            $jid=$this->journals->post(['company_id'=>(int)$operation->company_id,'branch_id'=>(int)$operation->from_branch_id,'entry_date'=>$operation->operation_date,'source_type'=>'INVENTORY_OPERATION_COST','source_id'=>(int)$cost->id,'description'=>'تكلفة تشغيل '.$operation->operation_number.' - '.$cost->cost_type,'lines'=>$lines,'is_system_generated'=>1,'created_by'=>$userId]);
            DB::table('inventory_operation_costs')->where('id',$cost->id)->update(['journal_entry_id'=>$jid,'posted_at'=>now(),'posted_by'=>$userId,'updated_at'=>now()]);$journalIds[]=$jid;
        }
        return $journalIds;
    }

    private function settingAccount(int $companyId, string $key): int
    {
        $id = DB::table('accounting_settings')->where('company_id',$companyId)->where('setting_key',$key)->value('account_id');
        if (!$id) throw new \RuntimeException('الحساب المحاسبي ' . $key . ' غير معرف للشركة.');
        return (int) $id;
    }

}
