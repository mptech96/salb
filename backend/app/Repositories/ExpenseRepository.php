<?php

namespace App\Repositories;

use App\Repositories\Base\BaseRepository;
use Illuminate\Support\Facades\DB;

class ExpenseRepository extends BaseRepository
{
    protected string $table = 'expenses';

    public function createExpense(array $data): int
    {
        return $this->create([
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'] ?? null,

            'expense_type_id' => $data['expense_type_id'],
            'expense_date' => $data['expense_date'],
            'scope_type' => $data['scope_type'] ?? 'GENERAL',

            'reference_type' => $data['reference_type'] ?? ($data['scope_type'] ?? 'GENERAL'),
            'reference_id' => $data['reference_id'] ?? null,

            'shipment_id' => $data['shipment_id'] ?? null,
            'car_id' => $data['car_id'] ?? null,
            'purchase_invoice_id' => $data['purchase_invoice_id'] ?? null,
            'sales_invoice_id' => $data['sales_invoice_id'] ?? null,
            'driver_id' => $data['driver_id'] ?? null,
            'worker_id' => $data['worker_id'] ?? null,

            'amount' => $data['amount'],
            'payment_status' => $data['payment_status'] ?? 'PAID',
            'payment_method' => $data['payment_method'] ?? 'CASH',
            'expense_effect' => $data['expense_effect'] ?? 'COST',

            'voucher_id' => null,
            'journal_entry_id' => null,

            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function findByCompany(int $companyId, int $id)
    {
        return DB::table($this->table)
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->first();
    }

    public function attachPosting(int $companyId, int $id, ?int $voucherId, ?int $journalEntryId): void
    {
        DB::table($this->table)
            ->where('company_id', $companyId)
            ->where('id', $id)
            ->update([
                'voucher_id' => $voucherId,
                'journal_entry_id' => $journalEntryId,
                'updated_at' => now(),
            ]);
    }
}