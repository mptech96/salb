<?php

namespace App\Core\Pipeline;

class AuthorizationStep extends PipelineStep
{
    public function handle(mixed $payload): mixed
    {
        return $payload;
    }
}