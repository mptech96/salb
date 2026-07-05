<?php

namespace App\Core\Pipeline;

class ValidationStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}