<?php

namespace App\Core\Pipeline;

class WorkflowStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}