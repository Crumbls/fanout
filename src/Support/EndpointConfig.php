<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Support;

class EndpointConfig
{
    /**
     * @param  array<string, string>  $headers
     * @param  array{attempts: int, backoff: string, base_seconds: int}  $retry
     * @param  array{per_minute?: int, burst?: int}|null  $rateLimit
     */
    public function __construct(
        public readonly string $profileName,
        public readonly string $name,
        public readonly string $url,
        public readonly bool $enabled,
        public readonly ?string $environment,
        public readonly int $timeout,
        public readonly array $headers,
        public readonly ?string $signer,
        public readonly ?string $signerSecret,
        public readonly ?string $signatureHeader,
        public readonly array $retry,
        public readonly ?array $rateLimit,
        public readonly ?string $transform,
        public readonly ?string $filter,
    ) {}

    public static function fromArray(string $profileName, string $name, array $config): self
    {
        return new self(
            profileName:     $profileName,
            name:            $name,
            url:             (string) ($config['url'] ?? ''),
            enabled:         (bool) ($config['enabled'] ?? true),
            environment:     $config['environment'] ?? null,
            timeout:         (int) ($config['timeout'] ?? 10),
            headers:         (array) ($config['headers'] ?? []),
            signer:          $config['signer'] ?? null,
            signerSecret:    $config['secret'] ?? null,
            signatureHeader: $config['signature_header'] ?? null,
            retry:           array_replace(
                ['attempts' => 5, 'backoff' => 'exponential', 'base_seconds' => 5],
                (array) ($config['retry'] ?? []),
            ),
            rateLimit:       $config['rate_limit'] ?? null,
            transform:       $config['transform'] ?? null,
            filter:          $config['filter'] ?? null,
        );
    }

    public function rateLimiterKey(): string
    {
        return "fanout:{$this->profileName}:{$this->name}";
    }

    public function backoffSeconds(int $attempt): int
    {
        $base = (int) $this->retry['base_seconds'];

        return match ($this->retry['backoff']) {
            'fixed'       => $base,
            'linear'      => $base * max(1, $attempt),
            'exponential' => $base * (2 ** max(0, $attempt - 1)),
            default       => $base,
        };
    }

    public function maxAttempts(): int
    {
        return (int) ($this->retry['attempts'] ?? 5);
    }
}
