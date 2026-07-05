<?php

namespace App\Core\Pipeline;

class AccountingStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}