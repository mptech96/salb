<?php

namespace App\Core\Pipeline;

class PersistenceStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}