<?php

namespace App\Services\Provisioning;

use App\Domain\Accounting\Services\AccountingBootstrapService;
use App\Services\Entitlement\EntitlementSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class CompanyProvisioningService
{
    public function __construct(
        private AccountingBootstrapService $accounting,
        private EntitlementSnapshotService $snapshots,
    ) {}

    public function provision(array $input): array
    {
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if ($key === '' || strlen($key) > 100) {
            throw new RuntimeException('A valid Idempotency-Key is required.');
        }

        $hash = $this->requestHash($input);

        try {
            return DB::transaction(fn () => $this->provisionLocked($key, $hash, $input), 3);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000') throw $e;
            $existing = DB::table('company_provisioning_requests')->where('idempotency_key', $key)->first();
            if (!$existing || $existing->request_hash !== $hash || $existing->status !== 'COMPLETED') throw $e;
            return [...json_decode($existing->result_json, true, flags: JSON_THROW_ON_ERROR), 'idempotent_replay' => true];
        }
    }

    private function provisionLocked(string $key, string $hash, array $input): array
    {
        $existing = DB::table('company_provisioning_requests')->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($existing) {
            if (!hash_equals((string) $existing->request_hash, $hash)) throw new RuntimeException('Idempotency-Key was already used with a different request.');
            if ($existing->status !== 'COMPLETED') throw new RuntimeException('Provisioning request is already in progress.');
            return [...json_decode($existing->result_json, true, flags: JSON_THROW_ON_ERROR), 'idempotent_replay' => true];
        }

        DB::table('company_provisioning_requests')->insert([
            'idempotency_key' => $key,
            'request_hash' => $hash,
            'channel' => $input['channel'],
            'status' => 'PROCESSING',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan = DB::table('plans')->where('id', (int) $input['plan_id'])->where('is_active', 1)->lockForUpdate()->first();
        if (!$plan) throw new RuntimeException('The selected plan is not available.');

        $role = DB::table('roles')->whereIn('role_code', ['COMPANY_OWNER', 'COMPANY_ADMIN'])
            ->where('is_active', 1)->orderByRaw("CASE WHEN role_code = 'COMPANY_OWNER' THEN 0 ELSE 1 END")->first();
        if (!$role || strtoupper((string) $role->role_code) === 'SUPER_ADMIN') throw new RuntimeException('A safe Company Owner role is not configured.');

        $username = trim((string) ($input['username'] ?? '')) ?: 'owner-'.Str::lower(Str::random(12));
        if (DB::table('users')->where('username', $username)->exists()) throw new RuntimeException('Username is already in use.');
        $temporaryPassword = empty($input['password']) ? Str::password(20) : null;
        $password = (string) ($input['password'] ?? $temporaryPassword);

        $companyId = DB::table('companies')->insertGetId([
            'company_name' => trim((string) $input['company_name']),
            'owner_name' => trim((string) $input['owner_name']),
            'phone' => trim((string) $input['phone']),
            'email' => $input['email'] ?? null,
            'city' => $input['city'] ?? null,
            'address' => $input['address'] ?? null,
            'is_active' => (int) ($input['company_is_active'] ?? 0),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $branchId = DB::table('branches')->insertGetId([
            'company_id' => $companyId, 'branch_name' => 'الفرع الرئيسي', 'branch_code' => 'MAIN-'.$companyId,
            'phone' => $input['phone'], 'city' => $input['city'] ?? null, 'address' => $input['address'] ?? null,
            'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ownerId = DB::table('users')->insertGetId([
            'company_id' => $companyId, 'branch_id' => $branchId, 'name' => trim((string) $input['owner_name']),
            'username' => $username, 'email' => $input['email'] ?? null, 'phone' => $input['phone'],
            'password' => Hash::make($password), 'is_active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('user_roles')->insert(['company_id'=>$companyId,'user_id'=>$ownerId,'role_id'=>$role->id,'is_active'=>1,'created_at'=>now(),'updated_at'=>now()]);

        $accounting = $this->accounting->bootstrapCompany($companyId, $branchId, $input['start_date'], $input['end_date']);
        $status = ($input['subscription_mode'] ?? 'PAID') === 'TRIAL' && ($input['trial_allowed'] ?? false) ? 'TRIAL' : 'PENDING';
        $subscriptionId = DB::table('subscriptions')->insertGetId([
            'company_id'=>$companyId,'plan_id'=>$plan->id,'start_date'=>$input['start_date'],'end_date'=>$input['end_date'],
            'status'=>$status,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $snapshotRows = $this->snapshots->capture($subscriptionId);

        $invoiceId = null;
        if ($status === 'PENDING') $invoiceId = $this->createInvoice($companyId, $subscriptionId, $ownerId, $plan, $input);
        $invoice = $invoiceId ? DB::table('subscription_invoices')->where('id', $invoiceId)->first() : null;
        DB::table('company_settings')->updateOrInsert(['company_id'=>$companyId],[
            'print_company_name'=>$input['company_name'],'print_phone'=>$input['phone'],'print_email'=>$input['email']??null,
            'print_city'=>$input['city']??null,'print_address'=>$input['address']??null,'currency_name'=>'ريال',
            'currency_code'=>strtoupper((string)($input['currency_code']??'SAR')),'primary_color'=>'#0B2A4A','secondary_color'=>'#123D68',
            'created_at'=>now(),'updated_at'=>now(),
        ]);

        $result = ['company_id'=>$companyId,'branch_id'=>$branchId,'owner_id'=>$ownerId,'username'=>$username,
            'subscription_id'=>$subscriptionId,'subscription_status'=>$status,'invoice_id'=>$invoiceId,
            'invoice_number'=>$invoice?->invoice_number,'invoice_status'=>$invoice?->status,
            'subtotal'=>$invoice?(float)$invoice->subtotal:null,'tax_rate'=>$invoice?(float)$invoice->tax_rate:null,
            'tax_amount'=>$invoice?(float)$invoice->tax_amount:null,'total_amount'=>$invoice?(float)$invoice->total_amount:null,
            'remaining_amount'=>$invoice?(float)$invoice->remaining_amount:null,'currency_code'=>$invoice?->currency_code,
            'billing_period'=>$invoice?->billing_period,'period_start'=>$input['start_date'],'period_end'=>$input['end_date'],
            'due_date'=>$invoice?->due_date,'company_active'=>(bool)($input['company_is_active']??false),
            'entitlement_snapshot_rows'=>$snapshotRows,'accounting'=>$accounting,'idempotent_replay'=>false];
        DB::table('company_provisioning_requests')->where('idempotency_key',$key)->update([
            'company_id'=>$companyId,'status'=>'COMPLETED','result_json'=>json_encode($result, JSON_THROW_ON_ERROR),
            'completed_at'=>now(),'updated_at'=>now(),
        ]);
        if ($temporaryPassword !== null) $result['temporary_password'] = $temporaryPassword;
        return $result;
    }

    private function createInvoice(int $companyId, int $subscriptionId, int $ownerId, object $plan, array $input): int
    {
        $period = strtoupper((string)($input['billing_period'] ?? 'YEARLY'));
        $months = ['MONTHLY'=>1,'QUARTERLY'=>3,'SEMI_ANNUAL'=>6,'YEARLY'=>12][$period] ?? 12;
        $subtotal = $period === 'YEARLY' ? (float)($plan->yearly_price ?? ((float)$plan->monthly_price*12)) : (float)$plan->monthly_price*$months;
        $taxRate = (float)($input['tax_rate'] ?? 0); $tax = round($subtotal*$taxRate/100,3); $total=round($subtotal+$tax,3);
        return DB::table('subscription_invoices')->insertGetId([
            'company_id'=>$companyId,'subscription_id'=>$subscriptionId,'plan_id'=>$plan->id,
            'invoice_number'=>'SUB-INV-'.Str::upper((string)Str::ulid()),'invoice_date'=>CarbonImmutable::today()->toDateString(),
            'due_date'=>CarbonImmutable::today()->addDays(3)->toDateString(),'subtotal'=>$subtotal,'discount_amount'=>0,
            'tax_rate'=>$taxRate,'tax_amount'=>$tax,'total_amount'=>$total,'paid_amount'=>0,'remaining_amount'=>$total,
            'currency_code'=>strtoupper((string)($input['currency_code']??'SAR')),'status'=>'UNPAID','billing_period'=>$period,
            'period_start'=>$input['start_date'],'period_end'=>$input['end_date'],'notes'=>'Company provisioning invoice.',
            'created_by'=>$ownerId,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function requestHash(array $input): string
    {
        if (isset($input['password'])) $input['password'] = hash('sha256', (string) $input['password']);
        unset($input['password_confirmation']);
        ksort($input);
        return hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));
    }
}
