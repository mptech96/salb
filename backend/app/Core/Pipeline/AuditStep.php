<?php

namespace App\Core\Pipeline;

class AuditStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}