<?php

namespace App\Modules\Shared\Kernel;

class UseCaseResult
{
    public function __construct(
        public bool $success,
        public string $message = '',
        public mixed $data = null,
        public ?int $statusCode = 200
    ) {}

    public static function success(string $message = '', mixed $data = null): self
    {
        return new self(true, $message, $data, 200);
    }

    public static function fail(string $message, int $statusCode = 400): self
    {
        return new self(false, $message, null, $statusCode);
    }
}