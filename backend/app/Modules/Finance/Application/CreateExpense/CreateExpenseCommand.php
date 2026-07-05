<?php

namespace App\Modules\Finance\Application\CreateExpense;

class CreateExpenseCommand
{
    public function __construct(
        public int $companyId,
        public ?int $branchId,
        public ?int $userId,
        public int $expenseTypeId,
        public string $expenseDate,
        public string $scopeType,
        public float $amount,
        public string $paymentStatus = 'PAID',
        public string $paymentMethod = 'CASH',
        public string $expenseEffect = 'COST',
        public ?int $referenceId = null,
        public ?int $shipmentId = null,
        public ?int $carId = null,
        public ?int $purchaseInvoiceId = null,
        public ?int $salesInvoiceId = null,
        public ?int $driverId = null,
        public ?int $workerId = null,
        public ?string $notes = null
    ) {}
}