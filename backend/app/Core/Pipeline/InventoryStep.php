<?php

namespace App\Core\Pipeline;

class InventoryStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}