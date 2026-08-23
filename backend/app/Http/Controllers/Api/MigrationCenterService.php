<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MigrationCenterService
{
    public function __construct(
        private EnterpriseInvoiceService $invoices,
        private DefaultPartyService $defaultParties,
    ) {}

    public function catalog(): array
    {
        return [
            'item_groups'=>$this->def('مجموعات الأصناف','MASTER','group_code',[
                'group_code'=>['كود المجموعة',false,['group_code','كود المجموعة','group code']],
                'group_name'=>['اسم المجموعة',true,['group_name','اسم المجموعة','group name']],
                'inventory_account_code'=>['حساب المخزون',false,['inventory_account_code','حساب المخزون']],
                'sales_account_code'=>['حساب المبيعات',false,['sales_account_code','حساب المبيعات']],
                'cogs_account_code'=>['حساب تكلفة المبيعات',false,['cogs_account_code','حساب تكلفة المبيعات','cogs']],
                'notes'=>['ملاحظات',false,['notes','ملاحظات']],
            ]),
            'item_categories'=>$this->def('فئات الأصناف','MASTER','category_code',[
                'category_code'=>['كود الفئة',false,['category_code','كود الفئة']],
                'category_name'=>['اسم الفئة',true,['category_name','اسم الفئة']],
                'group_code'=>['كود المجموعة',false,['group_code','كود المجموعة']],
                'parent_category_code'=>['الفئة الأب',false,['parent_category_code','كود الفئة الأب']],
                'inventory_account_code'=>['حساب المخزون',false,['inventory_account_code','حساب المخزون']],
                'sales_account_code'=>['حساب المبيعات',false,['sales_account_code','حساب المبيعات']],
                'cogs_account_code'=>['حساب تكلفة المبيعات',false,['cogs_account_code','حساب تكلفة المبيعات','cogs']],
                'notes'=>['ملاحظات',false,['notes','ملاحظات']],
            ]),
            'items'=>$this->def('الأصناف والخدمات','MASTER','item_code',[
                'item_code'=>['كود الصنف',false,['item_code','كود الصنف','item code']],
                'item_name'=>['اسم الصنف',true,['item_name','اسم الصنف','item name']],
                'item_grade'=>['الدرجة',false,['item_grade','الدرجة','grade']],
                'item_type'=>['النوع STOCK/SERVICE',false,['item_type','نوع الصنف','type']],
                'group_code'=>['كود المجموعة',false,['group_code','كود المجموعة']],
                'group_name'=>['اسم المجموعة',false,['group_name','اسم المجموعة']],
                'category_code'=>['كود الفئة',false,['category_code','كود الفئة']],
                'category_name'=>['اسم الفئة',false,['category_name','اسم الفئة']],
                'base_unit_code'=>['الوحدة الأساسية',false,['base_unit_code','الوحدة الأساسية']],
                'commercial_unit_code'=>['الوحدة التجارية',false,['commercial_unit_code','unit_name','الوحدة','الوحدة التجارية']],
                'commercial_to_base_factor'=>['معامل التحويل',false,['commercial_to_base_factor','معامل التحويل']],
                'default_buy_price'=>['سعر الشراء',false,['default_buy_price','سعر الشراء']],
                'default_sell_price'=>['سعر البيع',false,['default_sell_price','سعر البيع']],
                'min_sell_price'=>['أقل سعر بيع',false,['min_sell_price','اقل سعر بيع','أقل سعر بيع']],
                'inventory_account_code'=>['حساب المخزون',false,['inventory_account_code','حساب المخزون']],
                'sales_account_code'=>['حساب المبيعات',false,['sales_account_code','حساب المبيعات']],
                'cogs_account_code'=>['حساب COGS',false,['cogs_account_code','حساب cogs','حساب تكلفة المبيعات']],
                'purchase_expense_account_code'=>['مصروف شراء الخدمة',false,['purchase_expense_account_code','مصروف شراء الخدمة']],
                'track_inventory'=>['يتتبع المخزون',false,['track_inventory','يتتبع المخزون']],
                'can_purchase'=>['مسموح شراء',false,['can_purchase','مسموح شراء']],
                'can_sell'=>['مسموح بيع',false,['can_sell','مسموح بيع']],
                'is_waste_item'=>['فاقد/تالف',false,['is_waste_item','فاقد','تالف']],
                'is_byproduct'=>['ناتج ثانوي',false,['is_byproduct','ناتج ثانوي']],
                'notes'=>['ملاحظات',false,['notes','ملاحظات']],
            ]),
            'customers'=>$this->def('العملاء','MASTER','customer_code',[
                'customer_code'=>['كود العميل',false,['customer_code','كود العميل','code']],
                'customer_name'=>['اسم العميل',true,['customer_name','اسم العميل','name']],
                'phone'=>['الجوال',false,['phone','mobile','الجوال','الهاتف']],
                'email'=>['البريد',false,['email','البريد']],
                'tax_number'=>['الرقم الضريبي',false,['tax_number','vat_number','الرقم الضريبي']],
                'ledger_account_code'=>['حساب العميل',false,['ledger_account_code','حساب العميل']],
                'notes'=>['ملاحظات',false,['notes','ملاحظات']],
            ]),
            'suppliers'=>$this->def('الموردون','MASTER','supplier_code',[
                'supplier_code'=>['كود المورد',false,['supplier_code','كود المورد','code']],
                'supplier_name'=>['اسم المورد',true,['supplier_name','اسم المورد','name']],
                'phone'=>['الجوال',false,['phone','mobile','الجوال','الهاتف']],
                'email'=>['البريد',false,['email','البريد']],
                'tax_number'=>['الرقم الضريبي',false,['tax_number','vat_number','الرقم الضريبي']],
                'ledger_account_code'=>['حساب المورد',false,['ledger_account_code','حساب المورد']],
                'notes'=>['ملاحظات',false,['notes','ملاحظات']],
            ]),
            'cars'=>$this->def('السيارات','MASTER','plate_number',[
                'car_number'=>['رقم السيارة الداخلي',false,['car_number','رقم السيارة']],
                'plate_number'=>['اللوحة',true,['plate_number','رقم اللوحة','اللوحة']],
                'branch_code'=>['كود الفرع',false,['branch_code','كود الفرع']],
                'ownership_type'=>['نوع الملكية',false,['ownership_type','نوع الملكية']],
                'make_name'=>['الماركة',false,['make_name','الماركة']],
                'model_name'=>['الموديل',false,['model_name','الموديل']],
                'model_year'=>['سنة الصنع',false,['model_year','سنة الصنع']],
            ]),
            'drivers'=>$this->def('السائقون','MASTER','license_number',[
                'driver_name'=>['اسم السائق',true,['driver_name','اسم السائق','name']],
                'phone'=>['الجوال',false,['phone','mobile','الجوال']],
                'license_number'=>['رقم الرخصة',false,['license_number','رقم الرخصة']],
                'affiliation_type'=>['التبعية',false,['affiliation_type','التبعية']],
            ]),
            'sales_invoices'=>$this->invoiceDef('فواتير المبيعات','CUSTOMER'),
            'purchase_invoices'=>$this->invoiceDef('فواتير المشتريات','SUPPLIER'),
        ];
    }

    private function invoiceDef(string $label,string $party): array
    {
        $p=$party==='CUSTOMER'?'customer':'supplier';$pa=$party==='CUSTOMER'?'العميل':'المورد';
        return $this->def($label,'TRANSACTION','invoice_number',[
            'invoice_number'=>['رقم الفاتورة',true,['invoice_number','رقم الفاتورة','invoice no']],
            'invoice_date'=>['تاريخ الفاتورة',true,['invoice_date','تاريخ الفاتورة','date']],
            'branch_code'=>['كود الفرع',false,['branch_code','كود الفرع']],
            $p.'_code'=>['كود '.$pa,false,[$p.'_code','كود '.$pa]],
            $p.'_name'=>['اسم '.$pa,false,[$p.'_name','اسم '.$pa]],
            'car_plate'=>['لوحة السيارة',false,['car_plate','plate_number','لوحة السيارة']],
            'currency_code'=>['العملة',false,['currency_code','العملة']],
            'exchange_rate'=>['سعر الصرف',false,['exchange_rate','سعر الصرف']],
            'header_discount'=>['خصم الفاتورة',false,['header_discount','discount_amount','خصم الفاتورة']],
            'transport_cost'=>['النقل',false,['transport_cost','تكلفة النقل']],
            'extra_cost'=>['تكاليف إضافية',false,['extra_cost','تكاليف إضافية']],
            'item_code'=>['كود الصنف',true,['item_code','كود الصنف']],
            'item_name'=>['اسم الصنف',false,['item_name','اسم الصنف']],
            'quantity'=>['الكمية',true,['quantity','qty','الكمية']],
            'unit_code'=>['الوحدة',false,['unit_code','unit_name','الوحدة']],
            'unit_price'=>['سعر الوحدة',true,['unit_price','price','سعر الوحدة']],
            'line_discount'=>['خصم السطر',false,['line_discount','line_discount_amount','خصم السطر']],
            'vat_percent'=>['نسبة الضريبة',false,['vat_percent','tax_percent','نسبة الضريبة']],
            'tax_code'=>['كود الضريبة',false,['tax_code','كود الضريبة']],
            'notes'=>['ملاحظات',false,['notes','ملاحظات']],
        ]);
    }

    private function def(string $label,string $kind,string $key,array $fields): array
    {
        $out=[];foreach($fields as$code=>$d)$out[]=['code'=>$code,'label'=>$d[0],'required'=>$d[1],'aliases'=>$d[2]];
        return ['code'=>'','label'=>$label,'kind'=>$kind,'key_field'=>$key,'fields'=>$out];
    }

    public function prepareCatalog(): array
    {
        $x=$this->catalog();foreach($x as$k=>&$v)$v['code']=$k;return array_values($x);
    }

    public function parse(UploadedFile $file): array
    {
        $raw=file_get_contents($file->getRealPath());
        if($raw===false)throw new \RuntimeException('تعذر قراءة الملف.');
        return $this->parseRaw($raw,$file->getClientOriginalName());
    }

    public function parseRaw(string $raw,string $fileName='import.csv'): array
    {
        $ext=strtolower(pathinfo($fileName,PATHINFO_EXTENSION));
        if(!in_array($ext,['csv','txt'],true))throw new \RuntimeException('حاليًا الاستيراد المباشر يدعم CSV/UTF-8. يمكنك حفظ Excel بصيغة CSV ثم رفعه.');
        // Normalize CSV encoding without mb_detect_encoding. Some Windows PHP builds
        // reject Windows-1256 as an mbstring encoding name. UTF-8 is preferred;
        // legacy Arabic CSV files are converted with iconv using the best candidate.
        $raw=$this->normalizeCsvEncoding($raw);
        $first=strtok($raw,"\r\n")?:'';$candidates=[',',';',"\t"];$delimiter=',';$best=-1;
        foreach($candidates as$d){$n=substr_count($first,$d);if($n>$best){$best=$n;$delimiter=$d;}}
        $fp=fopen('php://temp','r+');fwrite($fp,$raw);rewind($fp);
        $headers=fgetcsv($fp,0,$delimiter)?:[];$headers=array_map(fn($x)=>trim((string)$x),$headers);
        if(!$headers)throw new \RuntimeException('الملف لا يحتوي على رؤوس أعمدة.');
        $rows=[];$n=1;while(($r=fgetcsv($fp,0,$delimiter))!==false){$n++;if(count(array_filter($r,fn($v)=>trim((string)$v)!==''))===0)continue;$r=array_pad($r,count($headers),null);$rows[]=['row_number'=>$n,'data'=>array_combine($headers,array_slice($r,0,count($headers)))];if(count($rows)>50000)throw new \RuntimeException('الحد الأقصى 50,000 سطر لكل ملف.');}
        fclose($fp);return ['headers'=>$headers,'rows'=>$rows,'delimiter'=>$delimiter];
    }

    private function normalizeCsvEncoding(string $raw): string
    {
        // UTF-8 BOM from Excel / SULB exports.
        if(str_starts_with($raw, "\xEF\xBB\xBF"))$raw=substr($raw,3);
        if($raw==='' || preg_match('//u',$raw)===1)return $raw;

        if(!function_exists('iconv'))
            throw new \RuntimeException('ترميز ملف CSV ليس UTF-8 ولا تتوفر أداة تحويل الترميز في PHP. احفظ الملف بصيغة CSV UTF-8 ثم أعد المحاولة.');

        // Score candidates instead of depending on mbstring encoding aliases.
        // Windows-1256 is the common legacy Arabic Excel encoding in our region.
        $candidates=['Windows-1256','CP1256','ISO-8859-6','Windows-1252'];
        $best=null;$bestScore=-PHP_INT_MAX;
        foreach($candidates as$enc){
            $converted=@iconv($enc,'UTF-8//IGNORE',$raw);
            if($converted===false || $converted==='' || preg_match('//u',$converted)!==1)continue;
            $arabic=preg_match_all('/[\x{0600}-\x{06FF}]/u',$converted,$m);
            $controls=preg_match_all('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u',$converted,$m2);
            $replacement=substr_count($converted,"�");
            $score=($arabic*10)-($controls*50)-($replacement*100);
            // Prefer Windows-1256 on equal scores because it preserves Arabic punctuation/letters best.
            if($enc==='Windows-1256')$score+=2;elseif($enc==='CP1256')$score+=1;
            if($score>$bestScore){$bestScore=$score;$best=$converted;}
        }
        if($best!==null)return $best;

        throw new \RuntimeException('تعذر التعرف على ترميز ملف CSV. احفظ الملف بصيغة CSV UTF-8 ثم أعد المحاولة.');
    }

    public function autoMapping(string $entity,array $headers): array
    {
        $def=$this->catalog()[$entity]??null;if(!$def)throw new \RuntimeException('نوع البيانات غير مدعوم.');
        $norm=[];foreach($headers as$h)$norm[$this->norm($h)]=$h;$map=[];
        foreach($def['fields']as$f){foreach(array_merge([$f['code']],$f['aliases'])as$a){$n=$this->norm($a);if(isset($norm[$n])){$map[$f['code']]=$norm[$n];break;}}}
        return $map;
    }

    public function preview(int $companyId,string $entity,array $parsed,array $mapping=[]): array
    {
        $mapping=$mapping?:$this->autoMapping($entity,$parsed['headers']);$sample=[];$valid=0;$invalid=0;
        foreach(array_slice($parsed['rows'],0,30)as$rr){$row=$this->mapped($rr['data'],$mapping);$errors=$this->validateBasic($entity,$row);$errors?$invalid++:$valid++;$sample[]=['row_number'=>$rr['row_number'],'data'=>$row,'errors'=>$errors];}
        return ['headers'=>$parsed['headers'],'mapping'=>$mapping,'sample'=>$sample,'sample_valid'=>$valid,'sample_invalid'=>$invalid,'total_rows'=>count($parsed['rows'])];
    }

    public function import(int $companyId,?int $fallbackBranchId,int $userId,string $entity,array $parsed,array $mapping,array $options,array $fileMeta): array
    {
        $mapping=$mapping?:$this->autoMapping($entity,$parsed['headers']);$source=trim((string)($options['source_system']??'Legacy System'))?:'Legacy System';
        $posting=strtoupper((string)($options['posting_mode']??'DRAFT'));if(!in_array($posting,['DRAFT','POST'],true))$posting='DRAFT';
        $batchId=DB::table('data_migration_batches')->insertGetId(['company_id'=>$companyId,'branch_id'=>$fallbackBranchId,'entity_code'=>$entity,'file_name'=>$fileMeta['name']??null,'source_system'=>$source,'import_mode'=>'UPSERT','posting_mode'=>$posting,'status'=>'RUNNING','total_rows'=>count($parsed['rows']),'options_json'=>json_encode($options,JSON_UNESCAPED_UNICODE),'started_by'=>$userId,'started_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $stats=['imported'=>0,'updated'=>0,'skipped'=>0,'failed'=>0];
        try{
            if(in_array($entity,['sales_invoices','purchase_invoices'],true))$this->importInvoices($companyId,$fallbackBranchId,$userId,$entity,$parsed['rows'],$mapping,$options,$batchId,$source,$stats);
            else foreach($parsed['rows']as$rr){$row=$this->mapped($rr['data'],$mapping);$errors=$this->validateBasic($entity,$row);if($errors){$stats['failed']++;$this->log($companyId,$batchId,$rr['row_number'],$this->externalKey($entity,$row),'ERROR',implode(' | ',$errors),$row);continue;}try{$status=$this->importMasterRow($companyId,$userId,$entity,$row,$batchId,$source,$options);$stats[strtolower($status)]++;$this->log($companyId,$batchId,$rr['row_number'],$this->externalKey($entity,$row),$status,null,$row);}catch(\Throwable $e){$stats['failed']++;$this->log($companyId,$batchId,$rr['row_number'],$this->externalKey($entity,$row),'ERROR',$e->getMessage(),$row);}}
        }finally{
            DB::table('data_migration_batches')->where('id',$batchId)->update(['status'=>$stats['failed']>0?'COMPLETED_WITH_ERRORS':'COMPLETED','valid_rows'=>$stats['imported']+$stats['updated']+$stats['skipped'],'imported_rows'=>$stats['imported']+$stats['updated'],'skipped_rows'=>$stats['skipped'],'failed_rows'=>$stats['failed'],'finished_at'=>now(),'updated_at'=>now()]);
        }
        return ['batch_id'=>$batchId,'stats'=>$stats];
    }

    private function importMasterRow(int $cid,int $uid,string $entity,array $r,int $batchId,string $source,array $options): string
    {
        return match($entity){
            'item_groups'=>$this->upsertGroup($cid,$r,$batchId,$source),
            'item_categories'=>$this->upsertCategory($cid,$r,$batchId,$source),
            'items'=>$this->upsertItem($cid,$r,$batchId,$source,(bool)($options['auto_create_groups_categories']??true)),
            'customers'=>$this->upsertParty($cid,'CUSTOMER',$r,$batchId,$source),
            'suppliers'=>$this->upsertParty($cid,'SUPPLIER',$r,$batchId,$source),
            'cars'=>$this->upsertCar($cid,$r,$batchId,$source),
            'drivers'=>$this->upsertDriver($cid,$r,$batchId,$source),
            default=>throw new \RuntimeException('نوع الاستيراد غير مدعوم.'),
        };
    }

    private function upsertGroup(int $cid,array $r,int $batch,string $source): string
    {
        $q=DB::table('item_groups')->where('company_id',$cid);$existing=!empty($r['group_code'])?(clone $q)->where('group_code',$r['group_code'])->first():(clone $q)->where('group_name',$r['group_name'])->first();
        $data=['company_id'=>$cid,'group_code'=>$r['group_code']?:null,'group_name'=>$r['group_name'],'inventory_account_id'=>$this->account($cid,$r['inventory_account_code']??null),'sales_account_id'=>$this->account($cid,$r['sales_account_code']??null),'cogs_account_id'=>$this->account($cid,$r['cogs_account_code']??null),'notes'=>$r['notes']??null,'is_active'=>1,'updated_at'=>now()];
        return $this->persist('item_groups',$existing,$data,$batch,$source,$r['group_code']??$r['group_name']);
    }

    private function upsertCategory(int $cid,array $r,int $batch,string $source): string
    {
        $existing=DB::table('item_categories')->where('company_id',$cid)->when(!empty($r['category_code']),fn($q)=>$q->where('category_code',$r['category_code']),fn($q)=>$q->where('category_name',$r['category_name']))->first();
        $data=['company_id'=>$cid,'category_code'=>$r['category_code']?:null,'category_name'=>$r['category_name'],'group_id'=>$this->groupId($cid,$r['group_code']??null,null,false),'parent_id'=>$this->categoryId($cid,$r['parent_category_code']??null,null,false),'inventory_account_id'=>$this->account($cid,$r['inventory_account_code']??null),'sales_account_id'=>$this->account($cid,$r['sales_account_code']??null),'cogs_account_id'=>$this->account($cid,$r['cogs_account_code']??null),'notes'=>$r['notes']??null,'is_active'=>1,'updated_at'=>now()];
        return $this->persist('item_categories',$existing,$data,$batch,$source,$r['category_code']??$r['category_name']);
    }

    private function upsertItem(int $cid,array $r,int $batch,string $source,bool $auto): string
    {
        $existing=DB::table('items')->where('company_id',$cid)->when(!empty($r['item_code']),fn($q)=>$q->where('item_code',$r['item_code']),fn($q)=>$q->where('item_name',$r['item_name']))->first();
        $type=$this->itemType($r['item_type']??'STOCK');$base=$type==='SERVICE'?'UNIT':$this->unit($r['base_unit_code']??'KG','KG');$commercial=$type==='SERVICE'?'UNIT':$this->unit($r['commercial_unit_code']??'TON','TON');
        $data=['company_id'=>$cid,'item_code'=>$r['item_code']?:null,'item_name'=>$r['item_name'],'item_grade'=>$r['item_grade']??null,'group_id'=>$this->groupId($cid,$r['group_code']??null,$r['group_name']??null,$auto),'category_id'=>$this->categoryId($cid,$r['category_code']??null,$r['category_name']??null,$auto),'item_type'=>$type,'track_inventory'=>$type==='SERVICE'?0:$this->bool($r['track_inventory']??1),'allow_negative_stock'=>0,'can_purchase'=>$this->bool($r['can_purchase']??1),'can_sell'=>$this->bool($r['can_sell']??1),'base_unit_code'=>$base,'commercial_unit_code'=>$commercial,'commercial_to_base_factor'=>$type==='SERVICE'?1:(float)($r['commercial_to_base_factor']?:($commercial==='TON'?1000:1)),'costing_method'=>'FIFO','default_buy_price'=>(float)($r['default_buy_price']?:0),'default_sell_price'=>(float)($r['default_sell_price']?:0),'min_sell_price'=>(float)($r['min_sell_price']?:0),'inventory_account_id'=>$this->account($cid,$r['inventory_account_code']??null),'sales_account_id'=>$this->account($cid,$r['sales_account_code']??null),'cogs_account_id'=>$this->account($cid,$r['cogs_account_code']??null),'purchase_expense_account_id'=>$this->account($cid,$r['purchase_expense_account_code']??null),'is_waste_item'=>$this->bool($r['is_waste_item']??0),'is_byproduct'=>$this->bool($r['is_byproduct']??0),'notes'=>$r['notes']??null,'is_active'=>1,'updated_at'=>now()];
        return $this->persist('items',$existing,$data,$batch,$source,$r['item_code']??$r['item_name']);
    }

    private function upsertParty(int $cid,string $type,array $r,int $batch,string $source): string
    {
        $table=$type==='CUSTOMER'?'customers':'suppliers';$nameCol=$type==='CUSTOMER'?'customer_name':'supplier_name';$codeCol=$type==='CUSTOMER'?'customer_code':'supplier_code';
        $code=$r[$codeCol]??null;$q=DB::table($table)->where('company_id',$cid);$existing=$code&&Schema::hasColumn($table,$codeCol)?(clone $q)->where($codeCol,$code)->first():(clone $q)->where($nameCol,$r[$nameCol])->first();
        $data=['company_id'=>$cid,$nameCol=>$r[$nameCol],'phone'=>$r['phone']??null,'email'=>$r['email']??null,'tax_number'=>$r['tax_number']??null,'ledger_account_id'=>$this->account($cid,$r['ledger_account_code']??null),'scope_all_branches'=>1,'is_active'=>1,'notes'=>$r['notes']??null,'updated_at'=>now()];if($code&&Schema::hasColumn($table,$codeCol))$data[$codeCol]=$code;
        return $this->persist($table,$existing,$data,$batch,$source,$code?:$r[$nameCol]);
    }

    private function upsertCar(int $cid,array $r,int $batch,string $source): string
    {
        $existing=DB::table('cars')->where('company_id',$cid)->where('plate_number',$r['plate_number'])->first();$data=['company_id'=>$cid,'car_number'=>$r['car_number']??null,'plate_number'=>$r['plate_number'],'branch_id'=>$this->branch($cid,$r['branch_code']??null),'ownership_type'=>strtoupper((string)($r['ownership_type']?:'OTHER')),'make_name'=>$r['make_name']??null,'model_name'=>$r['model_name']??null,'model_year'=>($r['model_year']??'')!==''?(int)$r['model_year']:null,'is_active'=>1,'updated_at'=>now()];return $this->persist('cars',$existing,$data,$batch,$source,$r['plate_number']);
    }

    private function upsertDriver(int $cid,array $r,int $batch,string $source): string
    {
        $q=DB::table('drivers')->where('company_id',$cid);$existing=!empty($r['license_number'])?(clone $q)->where('license_number',$r['license_number'])->first():(clone $q)->where('driver_name',$r['driver_name'])->first();$data=['company_id'=>$cid,'driver_name'=>$r['driver_name'],'phone'=>$r['phone']??null,'license_number'=>$r['license_number']??null,'affiliation_type'=>strtoupper((string)($r['affiliation_type']?:'INDEPENDENT')),'is_active'=>1,'updated_at'=>now()];return $this->persist('drivers',$existing,$data,$batch,$source,$r['license_number']??$r['driver_name']);
    }

    private function persist(string $table,?object $existing,array $data,int $batch,string $source,string $ref): string
    {
        $cols=array_flip(Schema::getColumnListing($table));$data=array_filter($data,fn($v,$k)=>isset($cols[$k]),ARRAY_FILTER_USE_BOTH);if(isset($cols['external_source_system']))$data['external_source_system']=$source;if(isset($cols['external_reference']))$data['external_reference']=$ref;if(isset($cols['migration_batch_id']))$data['migration_batch_id']=$batch;
        if($existing){DB::table($table)->where('id',$existing->id)->update($data);return 'UPDATED';}$data['created_at']=isset($cols['created_at'])?now():null;$data=array_filter($data,fn($v,$k)=>isset($cols[$k]),ARRAY_FILTER_USE_BOTH);DB::table($table)->insert($data);return 'IMPORTED';
    }

    private function importInvoices(int $cid,?int $fallbackBranch,int $uid,string $entity,array $rows,array $mapping,array $options,int $batch,string $source,array &$stats): void
    {
        $mode=$entity==='sales_invoices'?'SALE':'PURCHASE';$groups=[];foreach($rows as$rr){$r=$this->mapped($rr['data'],$mapping);$key=trim((string)($r['invoice_number']??''));if($key===''){$stats['failed']++;$this->log($cid,$batch,$rr['row_number'],null,'ERROR','رقم الفاتورة مطلوب.',$r);continue;}$groups[$key][]=['row_number'=>$rr['row_number'],'data'=>$r];}
        $defaults=$this->defaultParties->ensure($cid,$uid);$table=$mode==='SALE'?'sales_invoices':'purchase_invoices';$partyType=$mode==='SALE'?'CUSTOMER':'SUPPLIER';
        foreach($groups as$invoiceNo=>$group){$first=$group[0]['data'];try{$bid=$this->branch($cid,$first['branch_code']??null)?:$fallbackBranch;if(!$bid)$bid=(int)DB::table('branches')->where('company_id',$cid)->where('is_active',1)->orderBy('id')->value('id');if(!$bid)throw new \RuntimeException('لا يوجد فرع صالح للفاتورة.');$existing=DB::table($table)->where('company_id',$cid)->where('invoice_number',$invoiceNo)->first();if($existing&&strtoupper((string)$existing->document_status)!=='DRAFT'){$stats['skipped']++;$this->log($cid,$batch,$group[0]['row_number'],$invoiceNo,'SKIPPED','الفاتورة موجودة ومرحلة/غير قابلة للتحديث.',$first);continue;}
            $partyId=$this->party($cid,$partyType,$first)?:($mode==='SALE'?(int)$defaults['default_customer_id']:(int)$defaults['default_supplier_id']);$carId=$this->car($cid,$first['car_plate']??null);$items=[];
            foreach($group as$g){$r=$g['data'];$errs=$this->validateBasic($entity,$r);if($errs)throw new \RuntimeException('السطر '.$g['row_number'].': '.implode(' | ',$errs));$item=$this->item($cid,$r);if(!$item)throw new \RuntimeException('الصنف غير موجود في السطر '.$g['row_number'].'.');$qty=(float)$r['quantity'];$unit=$this->unit($r['unit_code']??($item->item_type==='SERVICE'?'UNIT':'KG'),$item->item_type==='SERVICE'?'UNIT':'KG');$line=['item_id'=>(int)$item->id,'unit_price'=>(float)$r['unit_price'],'discount_amount'=>(float)($r['line_discount']?:0),'vat_percent'=>($r['vat_percent']??'')!==''?(float)$r['vat_percent']:null,'tax_code_id'=>$this->taxCode($cid,$r['tax_code']??null),'notes'=>$r['notes']??null];if(strtoupper((string)$item->item_type)==='SERVICE'){$line['quantity']=$qty;$line['unit_code']='UNIT';$line['price_unit']='UNIT';}else{$line['qty_kg']=$unit==='TON'?round($qty*1000,3):round($qty,3);$line['price_unit']=$unit==='TON'?'TON':'KG';}$items[]=$line;}
            $payload=['branch_id'=>$bid,($mode==='SALE'?'customer_id':'supplier_id')=>$partyId,'car_id'=>$carId,'invoice_number'=>$invoiceNo,'invoice_date'=>$first['invoice_date'],'currency_code'=>$first['currency_code']?:null,'exchange_rate'=>($first['exchange_rate']??'')!==''?(float)$first['exchange_rate']:null,'discount_amount'=>(float)($first['header_discount']?:0),'transport_cost'=>(float)($first['transport_cost']?:0),'extra_cost'=>(float)($first['extra_cost']?:0),'notes'=>$first['notes']??null,'items'=>$items];
            $id=$this->invoices->saveDraft($mode,$payload,$cid,$bid,$uid,$existing?->id);if(Schema::hasColumn($table,'external_source_system'))DB::table($table)->where('id',$id)->update(['external_source_system'=>$source,'external_reference'=>$invoiceNo,'migration_batch_id'=>$batch,'updated_at'=>now()]);if(strtoupper((string)($options['posting_mode']??'DRAFT'))==='POST')$this->invoices->post($mode,$cid,$id,$uid,null);$status=$existing?'UPDATED':'IMPORTED';$stats[strtolower($status)]++;$this->log($cid,$batch,$group[0]['row_number'],$invoiceNo,$status,'تم استيراد '.count($items).' سطر للفاتورة.',$first);
        }catch(\Throwable $e){$stats['failed']++;$this->log($cid,$batch,$group[0]['row_number'],$invoiceNo,'ERROR',$e->getMessage(),$first);}}
    }

    public function exportRows(int $cid,string $entity): array
    {
        return match($entity){
            'item_groups'=>$this->exportGroups($cid),
            'item_categories'=>$this->exportCategories($cid),
            'items'=>$this->exportItems($cid),
            'customers'=>$this->exportParties($cid,'CUSTOMER'),
            'suppliers'=>$this->exportParties($cid,'SUPPLIER'),
            'cars'=>$this->exportCars($cid),
            'drivers'=>$this->exportDrivers($cid),
            'sales_invoices'=>$this->exportInvoices($cid,'SALE'),
            'purchase_invoices'=>$this->exportInvoices($cid,'PURCHASE'),
            default=>throw new \RuntimeException('نوع التصدير غير مدعوم.'),
        };
    }

    /*
     * التصدير هنا متعمد أن يرجع نفس أسماء أعمدة قالب الاستيراد، لا أسماء
     * جداول قاعدة البيانات الداخلية. النتيجة: Export من صلب يمكن Preview
     * ثم Import مرة أخرى مباشرة، كما يمكن استخدامها كقالب انتقال لنظام آخر.
     */
    private function exportGroups(int $cid): array
    {
        return DB::table('item_groups')->where('company_id',$cid)->orderBy('id')->get()->map(fn($x)=>[
            'group_code'=>$x->group_code,'group_name'=>$x->group_name,
            'inventory_account_code'=>$this->accountCodeById($cid,$x->inventory_account_id??null),
            'sales_account_code'=>$this->accountCodeById($cid,$x->sales_account_id??null),
            'cogs_account_code'=>$this->accountCodeById($cid,$x->cogs_account_id??null),
            'notes'=>$x->notes??null,
        ])->all();
    }

    private function exportCategories(int $cid): array
    {
        $rows=DB::table('item_categories as c')
            ->leftJoin('item_groups as g','g.id','=','c.group_id')
            ->leftJoin('item_categories as p','p.id','=','c.parent_id')
            ->where('c.company_id',$cid)->orderBy('c.id')
            ->select('c.*','g.group_code','p.category_code as parent_category_code')->get();
        return $rows->map(fn($x)=>[
            'category_code'=>$x->category_code,'category_name'=>$x->category_name,
            'group_code'=>$x->group_code,'parent_category_code'=>$x->parent_category_code,
            'inventory_account_code'=>$this->accountCodeById($cid,$x->inventory_account_id??null),
            'sales_account_code'=>$this->accountCodeById($cid,$x->sales_account_id??null),
            'cogs_account_code'=>$this->accountCodeById($cid,$x->cogs_account_id??null),
            'notes'=>$x->notes??null,
        ])->all();
    }

    private function exportItems(int $cid): array
    {
        $rows=DB::table('items as i')
            ->leftJoin('item_groups as g','g.id','=','i.group_id')
            ->leftJoin('item_categories as c','c.id','=','i.category_id')
            ->where('i.company_id',$cid)->orderBy('i.id')
            ->select('i.*','g.group_code','g.group_name','c.category_code','c.category_name')->get();
        return $rows->map(fn($x)=>[
            'item_code'=>$x->item_code,'item_name'=>$x->item_name,'item_grade'=>$x->item_grade,
            'item_type'=>$x->item_type,'group_code'=>$x->group_code,'group_name'=>$x->group_name,
            'category_code'=>$x->category_code,'category_name'=>$x->category_name,
            'base_unit_code'=>$x->base_unit_code,'commercial_unit_code'=>$x->commercial_unit_code,
            'commercial_to_base_factor'=>$x->commercial_to_base_factor,
            'default_buy_price'=>$x->default_buy_price,'default_sell_price'=>$x->default_sell_price,'min_sell_price'=>$x->min_sell_price,
            'inventory_account_code'=>$this->accountCodeById($cid,$x->inventory_account_id??null),
            'sales_account_code'=>$this->accountCodeById($cid,$x->sales_account_id??null),
            'cogs_account_code'=>$this->accountCodeById($cid,$x->cogs_account_id??null),
            'purchase_expense_account_code'=>$this->accountCodeById($cid,$x->purchase_expense_account_id??null),
            'track_inventory'=>(int)($x->track_inventory??0),'can_purchase'=>(int)($x->can_purchase??0),'can_sell'=>(int)($x->can_sell??0),
            'is_waste_item'=>(int)($x->is_waste_item??0),'is_byproduct'=>(int)($x->is_byproduct??0),'notes'=>$x->notes??null,
        ])->all();
    }

    private function exportParties(int $cid,string $type): array
    {
        $table=$type==='CUSTOMER'?'customers':'suppliers';$code=$type==='CUSTOMER'?'customer_code':'supplier_code';$name=$type==='CUSTOMER'?'customer_name':'supplier_name';
        return DB::table($table)->where('company_id',$cid)->orderBy('id')->get()->map(function($x)use($cid,$code,$name){return [
            $code=>$x->{$code}??null,$name=>$x->{$name},'phone'=>$x->phone??null,'email'=>$x->email??null,'tax_number'=>$x->tax_number??null,
            'ledger_account_code'=>$this->accountCodeById($cid,$x->ledger_account_id??null),'notes'=>$x->notes??null,
        ];})->all();
    }

    private function exportCars(int $cid): array
    {
        $rows=DB::table('cars as c')->leftJoin('branches as b','b.id','=','c.branch_id')->where('c.company_id',$cid)->orderBy('c.id')
            ->select('c.*','b.branch_code')->get();
        return $rows->map(fn($x)=>[
            'car_number'=>$x->car_number,'plate_number'=>$x->plate_number,'branch_code'=>$x->branch_code,
            'ownership_type'=>$x->ownership_type,'make_name'=>$x->make_name,'model_name'=>$x->model_name,'model_year'=>$x->model_year,
        ])->all();
    }

    private function exportDrivers(int $cid): array
    {
        return DB::table('drivers')->where('company_id',$cid)->orderBy('id')->get()->map(fn($x)=>[
            'driver_name'=>$x->driver_name,'phone'=>$x->phone??null,'license_number'=>$x->license_number??null,'affiliation_type'=>$x->affiliation_type??'INDEPENDENT',
        ])->all();
    }

    private function exportInvoices(int $cid,string $mode): array
    {
        $h=$mode==='SALE'?'sales_invoices':'purchase_invoices';$l=$mode==='SALE'?'sales_invoice_lines':'purchase_invoice_lines';$fk=$mode==='SALE'?'sales_invoice_id':'purchase_invoice_id';
        $partyIdCol=$mode==='SALE'?'customer_id':'supplier_id';$partyTable=$mode==='SALE'?'customers':'suppliers';$partyCodeCol=$mode==='SALE'?'customer_code':'supplier_code';$partyNameCol=$mode==='SALE'?'customer_name':'supplier_name';$out=[];
        $heads=DB::table($h.' as h')->leftJoin('branches as b','b.id','=','h.branch_id')->leftJoin('cars as car','car.id','=','h.car_id')->where('h.company_id',$cid)
            ->select('h.*','b.branch_code','car.plate_number as car_plate')->orderBy('h.id')->get();
        foreach($heads as$x){
            $party=$x->{$partyIdCol}?DB::table($partyTable)->where('company_id',$cid)->where('id',$x->{$partyIdCol})->first():null;
            $lines=DB::table($l.' as l')->leftJoin('items as i','i.id','=','l.item_id')->leftJoin('tax_codes as t','t.id','=','l.tax_code_id')
                ->where('l.company_id',$cid)->where('l.'.$fk,$x->id)->select('l.*','i.item_code','i.item_name','t.tax_code')->orderBy('l.id')->get();
            foreach($lines as$ln){
                $service=strtoupper((string)($ln->item_type_snapshot??'STOCK'))==='SERVICE'||(int)($ln->track_inventory_snapshot??1)!==1;
                $out[]=[
                    'invoice_number'=>$x->invoice_number,'invoice_date'=>$x->invoice_date,'branch_code'=>$x->branch_code,
                    ($mode==='SALE'?'customer_code':'supplier_code')=>$party?->{$partyCodeCol},
                    ($mode==='SALE'?'customer_name':'supplier_name')=>$party?->{$partyNameCol},
                    'car_plate'=>$x->car_plate,'currency_code'=>$x->currency_code,'exchange_rate'=>$x->exchange_rate,
                    'header_discount'=>$x->discount_amount??0,'transport_cost'=>$x->transport_cost??0,'extra_cost'=>$x->extra_cost??0,
                    'item_code'=>$ln->item_code,'item_name'=>$ln->item_name,
                    'quantity'=>$service?(float)($ln->quantity??$ln->qty??0):(float)($ln->qty_kg??0),
                    'unit_code'=>$service?($ln->unit_code??'UNIT'):'KG',
                    'unit_price'=>$ln->entered_unit_price??($service?($ln->unit_price??0):($ln->unit_price_per_kg??0)),
                    'line_discount'=>$ln->discount_amount??0,'vat_percent'=>$ln->vat_percent??$ln->tax_rate_snapshot??0,'tax_code'=>$ln->tax_code,'notes'=>$ln->notes??null,
                ];
            }
        }
        return $out;
    }

    private function accountCodeById(int $cid,$id): ?string
    {
        if(!$id||!Schema::hasTable('accounts'))return null;$cols=array_flip(Schema::getColumnListing('accounts'));
        $code=isset($cols['account_code'])?'account_code':(isset($cols['account_number'])?'account_number':(isset($cols['code'])?'code':null));if(!$code)return null;
        $q=DB::table('accounts')->where('id',(int)$id);if(isset($cols['company_id']))$q->where('company_id',$cid);$v=$q->value($code);return $v!==null?(string)$v:null;
    }

    public function template(string $entity): array
    {
        $def=$this->catalog()[$entity]??null;if(!$def)throw new \RuntimeException('القالب غير موجود.');$headers=array_map(fn($f)=>$f['code'],$def['fields']);$example=array_fill_keys($headers,'');
        if($entity==='items')$example=array_merge($example,['item_code'=>'CU-CABLE','item_name'=>'نحاس كيبل بجلدة','item_type'=>'STOCK','group_name'=>'معادن','category_name'=>'نحاس','base_unit_code'=>'KG','commercial_unit_code'=>'TON','commercial_to_base_factor'=>'1000','default_buy_price'=>'18.00','default_sell_price'=>'21.00','track_inventory'=>'1','can_purchase'=>'1','can_sell'=>'1']);
        if($entity==='sales_invoices')$example=array_merge($example,['invoice_number'=>'SAL-MIG-0001','invoice_date'=>date('Y-m-d'),'item_code'=>'IRON','quantity'=>'1000','unit_code'=>'KG','unit_price'=>'1.50','vat_percent'=>'15']);
        if($entity==='purchase_invoices')$example=array_merge($example,['invoice_number'=>'PUR-MIG-0001','invoice_date'=>date('Y-m-d'),'item_code'=>'IRON','quantity'=>'1000','unit_code'=>'KG','unit_price'=>'1.10','vat_percent'=>'15']);
        return ['headers'=>$headers,'example'=>$example];
    }

    public function history(int $cid): array{return DB::table('data_migration_batches')->where('company_id',$cid)->orderByDesc('id')->limit(100)->get()->map(fn($x)=>(array)$x)->all();}
    public function batch(int $cid,int $id): array{$b=DB::table('data_migration_batches')->where('company_id',$cid)->where('id',$id)->first();if(!$b)throw new \RuntimeException('دفعة الاستيراد غير موجودة.');$logs=DB::table('data_migration_row_logs')->where('company_id',$cid)->where('batch_id',$id)->orderBy('id')->limit(1000)->get();return ['batch'=>$b,'logs'=>$logs];}

    private function mapped(array $row,array $mapping): array{$out=[];foreach($mapping as$canonical=>$source)$out[$canonical]=trim((string)($row[$source]??''));return $out;}
    private function validateBasic(string $entity,array $r): array{$def=$this->catalog()[$entity]??null;$e=[];if(!$def)return ['نوع البيانات غير مدعوم.'];foreach($def['fields']as$f)if($f['required']&&trim((string)($r[$f['code']]??''))==='')$e[]=$f['label'].' مطلوب.';if(in_array($entity,['sales_invoices','purchase_invoices'],true)&&isset($r['quantity'])&&(float)$r['quantity']<=0)$e[]='الكمية يجب أن تكون أكبر من صفر.';return $e;}
    private function externalKey(string $entity,array $r): ?string{$d=$this->catalog()[$entity]??null;return $d?trim((string)($r[$d['key_field']]??''))?:null:null;}
    private function log(int $cid,int $batch,?int $row,?string $key,string $status,?string $message,array $payload): void{DB::table('data_migration_row_logs')->insert(['company_id'=>$cid,'batch_id'=>$batch,'row_number'=>$row,'external_key'=>$key,'row_status'=>$status,'message'=>$message,'payload_json'=>json_encode($payload,JSON_UNESCAPED_UNICODE),'created_at'=>now()]);}
    private function norm(string $s): string{$s=mb_strtolower(trim($s));$s=str_replace([' ','-','/','\\','.','(',')','[',']','_'],'',$s);return $s;}
    private function bool($v): int{$s=mb_strtolower(trim((string)$v));return in_array($s,['1','true','yes','y','نعم','صح','active'],true)?1:0;}
    private function itemType(string $v): string{$s=mb_strtolower(trim($v));return in_array($s,['service','خدمة','خدمي'],true)?'SERVICE':'STOCK';}
    private function unit(string $v,string $default): string{$s=mb_strtolower(trim($v));if(in_array($s,['ton','tons','طن','طنن'],true))return 'TON';if(in_array($s,['unit','وحدة','خدمة'],true))return 'UNIT';if(in_array($s,['kg','كجم','كيلو','كيلوجرام'],true))return 'KG';return strtoupper($v?:$default);}
    private function account(int $cid,?string $code): ?int{if(!$code||!Schema::hasTable('accounts'))return null;$cols=array_flip(Schema::getColumnListing('accounts'));$col=isset($cols['account_code'])?'account_code':(isset($cols['account_number'])?'account_number':(isset($cols['code'])?'code':null));if(!$col)return null;$q=DB::table('accounts')->where($col,$code);if(isset($cols['company_id']))$q->where('company_id',$cid);$id=$q->value('id');if(!$id)throw new \RuntimeException('الحساب '.$code.' غير موجود.');return (int)$id;}
    private function branch(int $cid,?string $code): ?int{if(!$code)return null;$q=DB::table('branches')->where('company_id',$cid);if(Schema::hasColumn('branches','branch_code'))$q->where('branch_code',$code);else$q->where('branch_name',$code);return ($id=$q->value('id'))?(int)$id:null;}
    private function groupId(int $cid,?string $code,?string $name,bool $auto): ?int{if(!$code&&!$name)return null;$q=DB::table('item_groups')->where('company_id',$cid);$x=$code?(clone $q)->where('group_code',$code)->first():(clone $q)->where('group_name',$name)->first();if($x)return (int)$x->id;if(!$auto)return null;return (int)DB::table('item_groups')->insertGetId(['company_id'=>$cid,'group_code'=>$code?:null,'group_name'=>$name?:$code,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);}
    private function categoryId(int $cid,?string $code,?string $name,bool $auto): ?int{if(!$code&&!$name)return null;$q=DB::table('item_categories')->where(fn($x)=>$x->where('company_id',$cid)->orWhereNull('company_id'));$x=$code?(clone $q)->where('category_code',$code)->first():(clone $q)->where('category_name',$name)->first();if($x)return (int)$x->id;if(!$auto)return null;return (int)DB::table('item_categories')->insertGetId(['company_id'=>$cid,'category_code'=>$code?:null,'category_name'=>$name?:$code,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);}
    private function party(int $cid,string $type,array $r): ?int{$table=$type==='CUSTOMER'?'customers':'suppliers';$codeCol=$type==='CUSTOMER'?'customer_code':'supplier_code';$nameCol=$type==='CUSTOMER'?'customer_name':'supplier_name';$code=$r[$codeCol]??null;$name=$r[$nameCol]??null;$q=DB::table($table)->where('company_id',$cid);if($code&&Schema::hasColumn($table,$codeCol))$id=(clone $q)->where($codeCol,$code)->value('id');elseif($name)$id=(clone $q)->where($nameCol,$name)->value('id');else$id=null;return $id?(int)$id:null;}
    private function taxCode(int $cid,?string $code): ?int{if(!$code||!Schema::hasTable('tax_codes'))return null;$id=DB::table('tax_codes')->where('company_id',$cid)->where('tax_code',$code)->value('id');if(!$id)throw new \RuntimeException('كود الضريبة '.$code.' غير موجود.');return (int)$id;}
    private function car(int $cid,?string $plate): ?int{return $plate?((int)(DB::table('cars')->where('company_id',$cid)->where('plate_number',$plate)->value('id')?:0)?:null):null;}
    private function item(int $cid,array $r): ?object{$q=DB::table('items')->where('company_id',$cid)->where('is_active',1);if(!empty($r['item_code']))$x=(clone $q)->where('item_code',$r['item_code'])->first();elseif(!empty($r['item_name']))$x=(clone $q)->where('item_name',$r['item_name'])->first();else$x=null;return $x;}
}
