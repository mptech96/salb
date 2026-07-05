<?php

namespace App\Core\Pipeline;

abstract class PipelineStep
{
    abstract public function handle(mixed $payload): mixed;
}