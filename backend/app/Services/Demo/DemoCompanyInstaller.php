<?php

namespace App\Services\Demo;

use App\Domain\Accounting\Services\JournalService;
use App\Services\Accounting\ExpensePosting;
use App\Services\Accounting\VoucherPosting;
use App\Services\CommercialDocumentService;
use App\Services\EnterpriseInvoiceService;
use App\Services\FinancialAccountService;
use App\Services\Provisioning\CompanyProvisioningService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

final class DemoCompanyInstaller
{
    public const MARKER='SULB_DEMO_INSTALL_V1';
    public const USERNAME='demo';

    public function __construct(private CompanyProvisioningService $provisioning,private EnterpriseInvoiceService $invoices,private CommercialDocumentService $documents,private ExpensePosting $expenses,private VoucherPosting $vouchers,private FinancialAccountService $money,private JournalService $journals,private DemoIntegrityService $integrity){}

    public function install(string $password, ?string $resetPassword=null): array
    {
        $existing=$this->companyId();
        if($existing){if($resetPassword!==null)$this->resetPassword($existing,$resetPassword);$status=$this->status($existing);return[...$status,'existing'=>true,'accounting_integrity'=>$this->integrity->validate($existing,(int)$status['financial_year_id'])];}
        if(DB::table('users')->where('username',self::USERNAME)->exists())throw new RuntimeException('Username demo is already used by a non-demo account.');
        $plan=DB::table('plans')->where('plan_code','ENTERPRISE')->where('is_active',1)->first()?:DB::table('plans')->where('is_active',1)->orderByDesc('id')->first();
        if(!$plan)throw new RuntimeException('No active subscription plan is available for the Demo company.');
        return DB::transaction(function()use($password,$plan){
            $today=CarbonImmutable::today();
            $provisioned=$this->provisioning->provision(['idempotency_key'=>self::MARKER,'channel'=>'PLATFORM_ADMIN','company_name'=>'صلب','owner_name'=>'مدير صلب التجريبي','phone'=>'0500000000','username'=>self::USERNAME,'password'=>$password,'plan_id'=>$plan->id,'billing_period'=>'YEARLY','start_date'=>$today->startOfYear()->toDateString(),'end_date'=>$today->endOfYear()->toDateString(),'subscription_mode'=>'TRIAL','trial_allowed'=>true,'company_is_active'=>true,'currency_code'=>'SAR']);
            $cid=(int)$provisioned['company_id'];$bid=(int)$provisioned['branch_id'];$uid=(int)$provisioned['owner_id'];$fy=(int)$provisioned['accounting']['financial_year_id'];
            [$customers,$suppliers]=$this->parties($cid,$bid);$items=$this->items($cid);$accounts=$this->financialAccounts($cid,$bid);
            $this->commercialDocuments($cid,$bid,$uid,$customers,$suppliers,$items,$today);
            $this->purchaseAndSales($cid,$bid,$uid,$customers,$suppliers,$items,$today);
            $this->expenseActivity($cid,$bid,$uid,$accounts,$today);
            $this->voucherActivity($cid,$bid,$uid,$accounts,$customers,$suppliers,$today);
            $this->manualJournals($cid,$bid,$uid,$today);
            $integrity=$this->integrity->validate($cid,$fy);
            return[...$this->status($cid),'existing'=>false,'accounting_integrity'=>$integrity];
        },3);
    }

    public function companyId(): ?int
    {$id=DB::table('company_provisioning_requests')->where('idempotency_key',self::MARKER)->where('status','COMPLETED')->value('company_id');return$id?(int)$id:null;}

    public function status(?int $companyId=null): array
    {
        $cid=$companyId?:$this->companyId();if(!$cid)throw new RuntimeException('Demo company is not installed.');
        $year=DB::table('financial_years')->where('company_id',$cid)->where('is_closed',0)->orderByDesc('start_date')->first();
        $counts=[];foreach(['customers','suppliers','items','purchase_orders','purchase_invoices','sales_quotations','sales_invoices','expenses','vouchers','fixed_assets']as$table)$counts[$table]=DB::table($table)->where('company_id',$cid)->count();$counts['posted_journals']=DB::table('journal_entries')->where('company_id',$cid)->where('status','POSTED')->count();
        return['company_id'=>$cid,'company_name'=>(string)DB::table('companies')->where('id',$cid)->value('company_name'),'login'=>(string)DB::table('users')->where('company_id',$cid)->where('username',self::USERNAME)->value('username'),'branch'=>(string)DB::table('branches')->where('company_id',$cid)->orderBy('id')->value('branch_name'),'financial_year'=>$year?->year_name,'financial_year_id'=>$year?->id,'counts'=>$counts];
    }

    private function resetPassword(int $companyId,string $password): void
    {if(strlen($password)<12)throw new RuntimeException('Reset password must contain at least 12 characters.');$updated=DB::table('users')->where('company_id',$companyId)->where('username',self::USERNAME)->update(['password'=>Hash::make($password),'updated_at'=>now()]);if($updated!==1)throw new RuntimeException('Demo owner account was not found.');}

    private function parties(int $cid,int $bid): array
    {
        $customers=[];for($i=1;$i<=10;$i++)$customers[]=DB::table('customers')->insertGetId(['company_id'=>$cid,'branch_id'=>$bid,'default_branch_id'=>$bid,'scope_all_branches'=>1,'customer_code'=>'DEMO-C'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'customer_name'=>'عميل تجريبي '.$i,'phone'=>'050100'.str_pad((string)$i,4,'0',STR_PAD_LEFT),'opening_balance'=>0,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $suppliers=[];for($i=1;$i<=8;$i++)$suppliers[]=DB::table('suppliers')->insertGetId(['company_id'=>$cid,'branch_id'=>$bid,'default_branch_id'=>$bid,'scope_all_branches'=>1,'supplier_code'=>'DEMO-S'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'supplier_name'=>'مورد تجريبي '.$i,'phone'=>'050200'.str_pad((string)$i,4,'0',STR_PAD_LEFT),'opening_balance'=>0,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);
        return[$customers,$suppliers];
    }

    private function items(int $cid): array
    {
        $names=['حديد سكراب','نحاس','ألمنيوم','ستانلس','بطاريات','كرتون','بلاستيك','رديترات','أسلاك نحاس','حديد ثقيل','علب ألمنيوم','مخلفات معدنية'];$settings=DB::table('accounting_settings')->where('company_id',$cid)->pluck('account_id','setting_key');
        $inventory=$settings['INVENTORY_ACCOUNT']??null;$sales=$settings['SALES_ACCOUNT']??$settings['SALES_REVENUE_ACCOUNT']??null;$cogs=$settings['COGS_ACCOUNT']??$settings['COST_OF_GOODS_SOLD_ACCOUNT']??null;if(!$inventory||!$sales||!$cogs)throw new RuntimeException('Demo item accounting settings are incomplete.');
        $ids=[];foreach($names as$i=>$name)$ids[]=DB::table('items')->insertGetId(['company_id'=>$cid,'item_code'=>'DEMO-I'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'item_name'=>$name,'item_type'=>'STOCK','track_inventory'=>1,'allow_negative_stock'=>0,'can_purchase'=>1,'can_sell'=>1,'base_unit_code'=>'KG','commercial_unit_code'=>'TON','commercial_to_base_factor'=>1000,'costing_method'=>'FIFO','unit_name'=>'طن','default_buy_price'=>1000+$i*80,'default_sell_price'=>1500+$i*100,'min_sell_price'=>1100+$i*80,'inventory_account_id'=>$inventory,'sales_account_id'=>$sales,'cogs_account_id'=>$cogs,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);return$ids;
    }

    private function financialAccounts(int$cid,int$bid): array
    {$cash=DB::table('financial_accounts')->where('company_id',$cid)->where('branch_id',$bid)->where('account_type','CASH')->first();if(!$cash)throw new RuntimeException('Demo default cash account is missing.');$bank=$this->money->save($cid,['branch_id'=>$bid,'account_code'=>'DEMO-BANK','account_name'=>'البنك التجريبي','account_type'=>'BANK','gl_account_id'=>$cash->gl_account_id,'currency_code'=>'SAR','is_active'=>1]);$wallet=$this->money->save($cid,['branch_id'=>$bid,'account_code'=>'DEMO-WALLET','account_name'=>'المحفظة التجريبية','account_type'=>'WALLET','gl_account_id'=>$cash->gl_account_id,'currency_code'=>'SAR','is_active'=>1]);return[(int)$cash->id,$bank,$wallet];}

    private function commercialDocuments(int$cid,int$bid,int$uid,array$c,array$s,array$items,CarbonImmutable$today): void
    {for($i=0;$i<6;$i++){$date=$this->demoDate($today,1+$i);$line=[['item_id'=>$items[$i%count($items)],'quantity'=>1+$i/10,'unit_code'=>'TON','qty_kg'=>1000+$i*100,'price_unit'=>'TON','unit_price'=>1600+$i*50,'vat_percent'=>0]];$this->documents->save('QUOTATION',['customer_id'=>$c[$i%count($c)],'document_date'=>$date,'document_number'=>'DEMO-Q-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'currency_code'=>'SAR','items'=>$line],$cid,$bid,$uid);$this->documents->save('PURCHASE_ORDER',['supplier_id'=>$s[$i%count($s)],'document_date'=>$date,'document_number'=>'DEMO-PO-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'currency_code'=>'SAR','items'=>$line],$cid,$bid,$uid);}}

    private function purchaseAndSales(int$cid,int$bid,int$uid,array$c,array$s,array$items,CarbonImmutable$today): void
    {for($i=0;$i<10;$i++){$date=$this->demoDate($today,15+$i);$item=$items[$i%count($items)];$purchase=$this->invoices->saveDraft('PURCHASE',['supplier_id'=>$s[$i%count($s)],'invoice_number'=>'DEMO-PINV-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'invoice_date'=>$date,'currency_code'=>'SAR','items'=>[['item_id'=>$item,'qty_kg'=>2000,'price_unit'=>'KG','unit_price'=>1.0+$i*.05,'vat_percent'=>0]]],$cid,$bid,$uid);$this->invoices->post('PURCHASE',$cid,$purchase,$uid,$bid);}
     for($i=0;$i<10;$i++){$date=$this->demoDate($today,30+$i);$item=$items[$i%count($items)];$sale=$this->invoices->saveDraft('SALE',['customer_id'=>$c[$i%count($c)],'invoice_number'=>'DEMO-SINV-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'invoice_date'=>$date,'currency_code'=>'SAR','items'=>[['item_id'=>$item,'qty_kg'=>600,'price_unit'=>'KG','unit_price'=>1.65+$i*.06,'vat_percent'=>0]]],$cid,$bid,$uid);$this->invoices->post('SALE',$cid,$sale,$uid,$bid);}}

    private function expenseActivity(int$cid,int$bid,int$uid,array$accounts,CarbonImmutable$today): void
    {$types=DB::table('expense_types')->where('is_active',1)->whereNotNull('account_id')->where(fn($q)=>$q->whereNull('company_id')->orWhere('company_id',$cid))->pluck('id')->all();if(!$types)throw new RuntimeException('No active expense types with GL accounts are available.');for($i=0;$i<10;$i++){$date=$this->demoDate($today,45+$i);$id=DB::table('expenses')->insertGetId(['company_id'=>$cid,'branch_id'=>$bid,'expense_type_id'=>$types[$i%count($types)],'expense_date'=>$date,'scope_type'=>'GENERAL','amount'=>100+$i*25,'payment_status'=>'PAID','payment_method'=>'CASH','financial_account_id'=>$accounts[$i%count($accounts)],'currency_code'=>'SAR','exchange_rate'=>1,'expense_effect'=>'COST','notes'=>'مصروف عرض تجريبي','created_at'=>now(),'updated_at'=>now()]);$type=DB::table('expense_types')->where('id',$types[$i%count($types)])->first();$result=$this->expenses->post(['company_id'=>$cid,'branch_id'=>$bid,'expense_id'=>$id,'amount'=>100+$i*25,'expense_date'=>$date,'payment_status'=>'PAID','payment_method'=>'CASH','financial_account_id'=>$accounts[$i%count($accounts)],'currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>100+$i*25,'expense_account_id'=>$type->account_id,'created_by'=>$uid]);if(!$result->success)throw new RuntimeException($result->message);}}

    private function voucherActivity(int$cid,int$bid,int$uid,array$accounts,array$c,array$s,CarbonImmutable$today): void
    {$receipt=DB::table('voucher_types')->where('type_code','RECEIPT')->value('id')?:1;$payment=DB::table('voucher_types')->where('type_code','PAYMENT')->value('id')?:2;for($i=0;$i<10;$i++){$isReceipt=$i<5;$id=DB::table('vouchers')->insertGetId(['company_id'=>$cid,'branch_id'=>$bid,'voucher_type_id'=>$isReceipt?$receipt:$payment,'voucher_number'=>'DEMO-'.($isReceipt?'REC':'PAY').'-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT),'voucher_date'=>$this->demoDate($today,55+$i),'reference_type'=>$isReceipt?'CUSTOMER':'SUPPLIER','reference_id'=>$isReceipt?$c[$i%count($c)]:$s[$i%count($s)],'amount'=>250+$i*20,'financial_account_id'=>$accounts[$i%count($accounts)],'payment_method'=>'CASH','currency_code'=>'SAR','exchange_rate'=>1,'foreign_amount'=>250+$i*20,'notes'=>'سند عرض تجريبي','created_by'=>$uid,'created_at'=>now(),'updated_at'=>now()]);$result=$this->vouchers->post(['company_id'=>$cid,'voucher_id'=>$id,'created_by'=>$uid]);if(!$result->success)throw new RuntimeException($result->message);}}

    private function manualJournals(int$cid,int$bid,int$uid,CarbonImmutable$today): void
    {$settings=DB::table('accounting_settings')->where('company_id',$cid)->pluck('account_id','setting_key');$debit=$settings['GENERAL_EXPENSE_ACCOUNT']??null;$credit=$settings['ACCRUED_EXPENSE_ACCOUNT']??null;if(!$debit||!$credit)return;for($i=1;$i<=2;$i++)$this->journals->post(['company_id'=>$cid,'branch_id'=>$bid,'entry_date'=>$this->demoDate($today,65+$i),'source_type'=>'DEMO_MANUAL','source_id'=>$i,'description'=>'قيد تسوية تجريبي '.$i,'lines'=>[['account_id'=>$debit,'debit'=>50*$i,'credit'=>0],['account_id'=>$credit,'debit'=>0,'credit'=>50*$i]],'created_by'=>$uid]);}

    private function demoDate(CarbonImmutable $today,int $dayOffset): string
    {return $today->startOfYear()->addDays(min(max(0,$dayOffset),max(0,$today->dayOfYear-1)))->toDateString();}
}
