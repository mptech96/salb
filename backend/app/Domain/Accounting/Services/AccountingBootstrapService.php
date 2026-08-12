<?php

namespace App\Domain\Accounting\Services;

use Illuminate\Support\Facades\DB;

class AccountingBootstrapService
{
    public function bootstrapCompany(
        int $companyId,
        int $mainBranchId,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        return DB::transaction(function () use (
            $companyId,
            $mainBranchId,
            $startDate,
            $endDate
        ) {
            $startDate = $startDate ?: now()->startOfYear()->toDateString();
            $endDate = $endDate ?: now()->endOfYear()->toDateString();

            $financialYearId = $this->ensureFinancialYear(
                $companyId,
                $startDate,
                $endDate
            );

            $companyCostCenterId = $this->ensureCompanyCostCenter($companyId);

            $branchCostCenterId = $this->ensureBranchCostCenter(
                $companyId,
                $mainBranchId,
                'الفرع الرئيسي'
            );

            $accounts = $this->ensureStandardAccounts($companyId);
            $this->ensureAccountingSettings($companyId, $accounts);

            return [
                'financial_year_id' => $financialYearId,
                'company_cost_center_id' => $companyCostCenterId,
                'branch_cost_center_id' => $branchCostCenterId,
                'accounts' => $accounts,
            ];
        });
    }

    public function bootstrapBranch(
        int $companyId,
        int $branchId,
        string $branchName
    ): int {
        return $this->ensureBranchCostCenter(
            $companyId,
            $branchId,
            $branchName
        );
    }

    private function ensureFinancialYear(
        int $companyId,
        string $startDate,
        string $endDate
    ): int {
        $yearName = date('Y', strtotime($startDate));

        $existing = DB::table('financial_years')
            ->where('company_id', $companyId)
            ->where('year_name', $yearName)
            ->first();

        if ($existing) {
            return (int) $existing->id;
        }

        return DB::table('financial_years')->insertGetId([
            'company_id' => $companyId,
            'year_name' => $yearName,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_closed' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureCompanyCostCenter(int $companyId): int
    {
        $existing = DB::table('cost_centers')
            ->where('company_id', $companyId)
            ->whereNull('branch_id')
            ->where('cost_center_code', 'CC-COMPANY')
            ->first();

        if ($existing) {
            return (int) $existing->id;
        }

        return DB::table('cost_centers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => null,
            'parent_id' => null,
            'cost_center_code' => 'CC-COMPANY',
            'cost_center_name' => 'مركز الشركة العام',
            'is_group' => 1,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureBranchCostCenter(
        int $companyId,
        int $branchId,
        string $branchName
    ): int {
        $existing = DB::table('cost_centers')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->first();

        if ($existing) {
            return (int) $existing->id;
        }

        $parentId = $this->ensureCompanyCostCenter($companyId);

        return DB::table('cost_centers')->insertGetId([
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'parent_id' => $parentId,
            'cost_center_code' => 'CC-BR-' . $branchId,
            'cost_center_name' => 'مركز تكلفة ' . $branchName,
            'is_group' => 0,
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensureStandardAccounts(int $companyId): array
    {
        $definitions = [
            ['1000','الأصول','ASSET','DEBIT',1,null,1,0],
            ['1100','النقدية وما في حكمها','ASSET','DEBIT',1,'1000',2,0],
            ['1110','الصندوق','ASSET','DEBIT',0,'1100',3,1],
            ['1120','البنوك','ASSET','DEBIT',0,'1100',3,1],
            ['1130','المحافظ والحسابات الإلكترونية','ASSET','DEBIT',0,'1100',3,1],

            ['1200','الذمم المدينة','ASSET','DEBIT',1,'1000',2,0],
            ['1210','العملاء','ASSET','DEBIT',0,'1200',3,1],
            ['1220','ذمم مدينة أخرى','ASSET','DEBIT',0,'1200',3,1],
            ['1230','دفعات مقدمة للموردين','ASSET','DEBIT',0,'1200',3,1],

            ['1300','المخزون','ASSET','DEBIT',1,'1000',2,0],
            ['1310','مخزون مواد وبضائع','ASSET','DEBIT',0,'1300',3,1],
            ['1320','مخزون بالطريق','ASSET','DEBIT',0,'1300',3,1],
            ['1330','فروقات وتسويات المخزون','ASSET','DEBIT',0,'1300',3,1],

            ['1400','الضرائب المدينة','ASSET','DEBIT',1,'1000',2,0],
            ['1410','ضريبة المدخلات','ASSET','DEBIT',0,'1400',3,0],
            ['1420','ضرائب ورسوم مستردة','ASSET','DEBIT',0,'1400',3,0],

            ['1500','العهد والسلف','ASSET','DEBIT',1,'1000',2,0],
            ['1510','سلف العاملين','ASSET','DEBIT',0,'1500',3,1],
            ['1520','عهد السائقين','ASSET','DEBIT',0,'1500',3,1],
            ['1530','عهد الفروع','ASSET','DEBIT',0,'1500',3,1],

            ['1600','الأصول الثابتة','ASSET','DEBIT',1,'1000',2,0],
            ['1610','أراضٍ','ASSET','DEBIT',0,'1600',3,1],
            ['1620','مبانٍ وإنشاءات','ASSET','DEBIT',0,'1600',3,1],
            ['1630','سيارات وشاحنات','ASSET','DEBIT',0,'1600',3,1],
            ['1640','معدات وآلات','ASSET','DEBIT',0,'1600',3,1],
            ['1650','موازين ومعدات وزن','ASSET','DEBIT',0,'1600',3,1],
            ['1660','أثاث وتجهيزات','ASSET','DEBIT',0,'1600',3,1],
            ['1670','أجهزة وأنظمة تقنية','ASSET','DEBIT',0,'1600',3,1],

            ['1700','مجمع الإهلاك','ASSET','CREDIT',1,'1000',2,0],
            ['1710','مجمع إهلاك المباني','ASSET','CREDIT',0,'1700',3,1],
            ['1720','مجمع إهلاك السيارات والشاحنات','ASSET','CREDIT',0,'1700',3,1],
            ['1730','مجمع إهلاك المعدات والآلات','ASSET','CREDIT',0,'1700',3,1],
            ['1740','مجمع إهلاك الموازين','ASSET','CREDIT',0,'1700',3,1],
            ['1750','مجمع إهلاك الأثاث والأجهزة','ASSET','CREDIT',0,'1700',3,1],

            ['2000','الالتزامات','LIABILITY','CREDIT',1,null,1,0],
            ['2100','الموردون','LIABILITY','CREDIT',0,'2000',2,1],
            ['2200','مستحقات العاملين','LIABILITY','CREDIT',0,'2000',2,1],
            ['2300','الضرائب المستحقة','LIABILITY','CREDIT',0,'2000',2,0],
            ['2400','المصروفات المستحقة','LIABILITY','CREDIT',0,'2000',2,1],
            ['2500','دفعات العملاء المقدمة','LIABILITY','CREDIT',0,'2000',2,1],
            ['2600','القروض والتمويل','LIABILITY','CREDIT',0,'2000',2,1],
            ['2700','التزامات أخرى','LIABILITY','CREDIT',0,'2000',2,1],

            ['3000','حقوق الملكية','EQUITY','CREDIT',1,null,1,0],
            ['3100','رأس المال','EQUITY','CREDIT',0,'3000',2,0],
            ['3200','الاحتياطيات','EQUITY','CREDIT',0,'3000',2,0],
            ['3300','الأرباح المحتجزة','EQUITY','CREDIT',0,'3000',2,0],
            ['3400','نتيجة السنة الحالية','EQUITY','CREDIT',0,'3000',2,0],
            ['3500','المسحوبات والتوزيعات','EQUITY','DEBIT',0,'3000',2,0],

            ['4000','الإيرادات','REVENUE','CREDIT',1,null,1,0],
            ['4100','إيرادات المبيعات','REVENUE','CREDIT',0,'4000',2,1],
            ['4200','إيرادات خدمات النقل','REVENUE','CREDIT',0,'4000',2,1],
            ['4300','إيرادات خدمات الوزن','REVENUE','CREDIT',0,'4000',2,1],
            ['4400','إيرادات تشغيلية أخرى','REVENUE','CREDIT',0,'4000',2,1],
            ['4500','أرباح بيع الأصول','REVENUE','CREDIT',0,'4000',2,1],
            ['4600','خصومات مكتسبة','REVENUE','CREDIT',0,'4000',2,1],

            ['5000','تكلفة الإيرادات','EXPENSE','DEBIT',1,null,1,0],
            ['5100','تكلفة البضاعة المباعة','EXPENSE','DEBIT',0,'5000',2,1],
            ['5200','تكاليف الشحن المباشرة','EXPENSE','DEBIT',0,'5000',2,1],
            ['5300','تكاليف التحميل والتنزيل','EXPENSE','DEBIT',0,'5000',2,1],
            ['5400','تكاليف الفرز والتجهيز','EXPENSE','DEBIT',0,'5000',2,1],
            ['5500','فروقات وهالك المخزون','EXPENSE','DEBIT',0,'5000',2,1],

            ['6000','المصروفات التشغيلية','EXPENSE','DEBIT',1,null,1,0],
            ['6100','الرواتب والأجور','EXPENSE','DEBIT',0,'6000',2,1],
            ['6200','عمولات العاملين','EXPENSE','DEBIT',0,'6000',2,1],
            ['6300','النقل والمحروقات','EXPENSE','DEBIT',0,'6000',2,1],
            ['6400','صيانة السيارات والشاحنات','EXPENSE','DEBIT',0,'6000',2,1],
            ['6500','صيانة المعدات والموازين','EXPENSE','DEBIT',0,'6000',2,1],
            ['6600','الإيجارات','EXPENSE','DEBIT',0,'6000',2,1],
            ['6700','الكهرباء والمياه','EXPENSE','DEBIT',0,'6000',2,1],
            ['6800','الاتصالات والأنظمة','EXPENSE','DEBIT',0,'6000',2,1],
            ['6900','المصروفات الإدارية والعامة','EXPENSE','DEBIT',0,'6000',2,1],

            ['7000','الإهلاك والمصروفات الأخرى','EXPENSE','DEBIT',1,null,1,0],
            ['7100','مصروف إهلاك المباني','EXPENSE','DEBIT',0,'7000',2,1],
            ['7200','مصروف إهلاك السيارات والشاحنات','EXPENSE','DEBIT',0,'7000',2,1],
            ['7300','مصروف إهلاك المعدات والآلات','EXPENSE','DEBIT',0,'7000',2,1],
            ['7400','مصروف إهلاك الموازين','EXPENSE','DEBIT',0,'7000',2,1],
            ['7500','مصروف إهلاك الأثاث والأجهزة','EXPENSE','DEBIT',0,'7000',2,1],
            ['7600','خسائر بيع واستبعاد الأصول','EXPENSE','DEBIT',0,'7000',2,1],
            ['7700','مصروفات أخرى','EXPENSE','DEBIT',0,'7000',2,1],
        ];

        $idsByCode = [];

        foreach ($definitions as $row) {
            [$code,$name,$type,$normalSide,$isGroup,$parentCode,$level,$allowCostCenter] = $row;

            $parentId = $parentCode ? ($idsByCode[$parentCode] ?? null) : null;

            $existing = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('account_code', $code)
                ->first();

            $data = [
                'parent_id' => $parentId,
                'account_name' => $name,
                'account_type' => $type,
                'normal_side' => $normalSide,
                'account_level' => $level,
                'is_group' => $isGroup,
                'allow_posting' => $isGroup ? 0 : 1,
                'allow_cost_center' => $allowCostCenter,
                'is_active' => 1,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('accounts')->where('id', $existing->id)->update($data);
                $idsByCode[$code] = (int) $existing->id;
            } else {
                $idsByCode[$code] = DB::table('accounts')->insertGetId([
                    'company_id' => $companyId,
                    'account_code' => $code,
                    'notes' => null,
                    'created_at' => now(),
                    ...$data,
                ]);
            }
        }

        return $idsByCode;
    }

    private function ensureAccountingSettings(
        int $companyId,
        array $accounts
    ): void {
        $map = [
            'CASH_ACCOUNT' => '1110',
            'BANK_ACCOUNT' => '1120',
            'CUSTOMER_ACCOUNT' => '1210',
            'INVENTORY_ACCOUNT' => '1310',
            'VAT_INPUT_ACCOUNT' => '1410',
            'WORKER_LOAN_ACCOUNT' => '1510',
            'FIXED_ASSET_ACCOUNT' => '1640',
            'ACCUMULATED_DEPRECIATION_ACCOUNT' => '1730',
            'SUPPLIER_ACCOUNT' => '2100',
            'WORKER_PAYABLE_ACCOUNT' => '2200',
            'VAT_OUTPUT_ACCOUNT' => '2300',
            'SALES_ACCOUNT' => '4100',
            'COGS_ACCOUNT' => '5100',
            'TRANSPORT_EXPENSE_ACCOUNT' => '6300',
            'SALARY_EXPENSE_ACCOUNT' => '6100',
            'WORKER_COMMISSION_EXPENSE_ACCOUNT' => '6200',
            'GENERAL_EXPENSE_ACCOUNT' => '6900',
            'DEPRECIATION_EXPENSE_ACCOUNT' => '7300',
            'MAINTENANCE_EXPENSE_ACCOUNT' => '6500',
            'ASSET_DISPOSAL_GAIN_ACCOUNT' => '4500',
            'ASSET_DISPOSAL_LOSS_ACCOUNT' => '7600',
            'RETAINED_EARNINGS_ACCOUNT' => '3300',
            'CURRENT_YEAR_RESULT_ACCOUNT' => '3400',
            'ACCRUED_EXPENSE_ACCOUNT' => '2400',
            'DRIVER_ADVANCE_ACCOUNT' => '1520',
            'INVENTORY_ADJUSTMENT_ACCOUNT' => '5500',
        ];

        foreach ($map as $key => $code) {
            DB::table('accounting_settings')->updateOrInsert(
                [
                    'company_id' => $companyId,
                    'setting_key' => $key,
                ],
                [
                    'account_id' => $accounts[$code],
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
}
