<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Support;

use Throwable;

class DeliveryAttempt
{
    public function __construct(
        public readonly bool $succeeded,
        public readonly ?int $statusCode,
        public readonly ?string $responseBody,
        public readonly ?Throwable $error,
    ) {}

    public static function success(int $statusCode, ?string $body): self
    {
        return new self(true, $statusCode, $body, null);
    }

    public static function failure(?int $statusCode, ?string $body, ?Throwable $error): self
    {
        return new self(false, $statusCode, $body, $error);
    }

    public function errorMessage(): ?string
    {
        return $this->error?->getMessage();
    }
}
