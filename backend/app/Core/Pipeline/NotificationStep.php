<?php

namespace App\Core\Pipeline;

class NotificationStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}