<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Repositories\AccountRepository;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function __construct(
        private AccountRepository $accounts
    ) {}

    /**
     * إنشاء حساب جديد
     */
    public function create(array $data): int
    {
        return DB::transaction(function () use ($data) {

            $companyId = (int) $data['company_id'];

            // منع تكرار الكود داخل نفس الشركة
            if ($this->accounts->findByCode($companyId, $data['account_code'])) {
                throw new \Exception('رقم الحساب مستخدم مسبقًا.');
            }

            // التحقق من الحساب الأب إن وجد
            if (!empty($data['parent_id'])) {

                $parent = $this->accounts->find($companyId, (int)$data['parent_id']);

                if (!$parent) {
                    throw new \Exception('الحساب الأب غير موجود.');
                }

                if (!$parent->is_group) {
                    throw new \Exception('لا يمكن إضافة حساب أسفل حساب تحليلي.');
                }
            }

            return $this->accounts->create($data);
        });
    }

    /**
     * جلب شجرة الحسابات
     */
    public function tree(int $companyId)
    {
        return $this->accounts->tree($companyId);
    }

    /**
     * جلب الحسابات التحليلية فقط
     */
    public function postingAccounts(int $companyId)
    {
        return $this->accounts->movementAccounts($companyId);
    }
}