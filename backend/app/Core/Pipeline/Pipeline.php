<?php

namespace App\Core\Pipeline;

class Pipeline
{
    private array $steps = [];

    public static function make(): self
    {
        return new self();
    }

    public function through(array $steps): self
    {
        $this->steps = $steps;
        return $this;
    }

    public function run(mixed $payload): mixed
    {
        foreach ($this->steps as $step) {
            if (is_string($step)) {
                $step = app($step);
            }

            if ($step instanceof PipelineStep) {
                $payload = $step->handle($payload);
            }
        }

        return $payload;
    }
}