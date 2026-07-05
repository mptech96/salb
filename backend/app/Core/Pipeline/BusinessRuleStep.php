<?php

namespace App\Core\Pipeline;

class BusinessRuleStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}