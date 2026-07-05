<?php

namespace App\Core\Contracts;

interface BusinessEngineInterface
{
    public function createExpense(array $data);
}