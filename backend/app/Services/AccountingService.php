<?php

namespace App\Services;

use App\Domain\Accounting\Services\JournalService;

class AccountingService
{
    public function __construct(
        private JournalService $journalService
    ) {}

    public function post(array $data): int
    {
        return $this->journalService->post($data);
    }
}