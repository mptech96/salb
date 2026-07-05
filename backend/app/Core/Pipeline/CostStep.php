<?php

namespace App\Core\Pipeline;

class CostStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}