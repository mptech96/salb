<?php

namespace App\Core\Pipeline;

class EventStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}