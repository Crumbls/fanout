<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Support;

class ProfileConfig
{
    /**
     * @param  array<string, EndpointConfig>  $endpoints
     */
    public function __construct(
        public readonly string $name,
        public readonly string $persist,
        public readonly ?string $validator,
        public readonly ?string $secret,
        public readonly ?string $signatureHeader,
        public readonly bool $continueOnEndpointFailure,
        public readonly array $endpoints,
    ) {}

    public static function fromArray(string $name, array $config): self
    {
        $endpoints = [];

        foreach ((array) ($config['endpoints'] ?? []) as $endpointName => $endpointConfig) {
            $endpoints[$endpointName] = EndpointConfig::fromArray($name, $endpointName, (array) $endpointConfig);
        }

        return new self(
            name:                       $name,
            persist:                    self::normalizePersist($config['persist'] ?? 'full'),
            validator:                  $config['validator'] ?? null,
            secret:                     $config['secret'] ?? null,
            signatureHeader:            $config['signature_header'] ?? null,
            continueOnEndpointFailure:  (bool) ($config['continue_on_endpoint_failure'] ?? true),
            endpoints:                  $endpoints,
        );
    }

    /**
     * @return array<string, EndpointConfig>
     */
    public function enabledEndpoints(): array
    {
        return array_filter($this->endpoints, fn (EndpointConfig $e) => $e->enabled);
    }

    public function shouldPersist(): bool
    {
        return $this->persist !== 'none';
    }

    public function shouldStorePayload(): bool
    {
        return $this->persist === 'full';
    }

    protected static function normalizePersist(string $mode): string
    {
        return match ($mode) {
            'full', 'metadata', 'none' => $mode,
            default                    => 'full',
        };
    }
}
