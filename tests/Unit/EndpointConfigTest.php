<?php

declare(strict_types=1);

use Crumbls\Fanout\Support\EndpointConfig;

it('builds with sensible defaults from a minimal config array', function (): void {
    $endpoint = EndpointConfig::fromArray('stripe', 'staging', [
        'url' => 'https://staging.example.test/hooks',
    ]);

    expect($endpoint->profileName)->toBe('stripe');
    expect($endpoint->name)->toBe('staging');
    expect($endpoint->url)->toBe('https://staging.example.test/hooks');
    expect($endpoint->enabled)->toBeTrue();
    expect($endpoint->environment)->toBeNull();
    expect($endpoint->timeout)->toBe(10);
    expect($endpoint->headers)->toBe([]);
    expect($endpoint->signer)->toBeNull();
    expect($endpoint->retry)->toBe(['attempts' => 5, 'backoff' => 'exponential', 'base_seconds' => 5]);
    expect($endpoint->rateLimit)->toBeNull();
});

it('preserves explicit overrides for retry config', function (): void {
    $endpoint = EndpointConfig::fromArray('stripe', 'dev', [
        'url'   => 'https://dev.example.test',
        'retry' => ['attempts' => 2, 'backoff' => 'linear', 'base_seconds' => 30],
    ]);

    expect($endpoint->retry['attempts'])->toBe(2);
    expect($endpoint->retry['backoff'])->toBe('linear');
    expect($endpoint->retry['base_seconds'])->toBe(30);
});

it('produces a stable rate-limiter key per profile/endpoint pair', function (): void {
    $a = EndpointConfig::fromArray('stripe', 'staging', ['url' => 'x']);
    $b = EndpointConfig::fromArray('stripe', 'dev',     ['url' => 'x']);
    $c = EndpointConfig::fromArray('github', 'staging', ['url' => 'x']);

    expect($a->rateLimiterKey())->toBe('fanout:stripe:staging');
    expect($b->rateLimiterKey())->toBe('fanout:stripe:dev');
    expect($c->rateLimiterKey())->toBe('fanout:github:staging');
});

it('computes fixed backoff', function (): void {
    $endpoint = EndpointConfig::fromArray('p', 'e', [
        'url'   => 'x',
        'retry' => ['attempts' => 5, 'backoff' => 'fixed', 'base_seconds' => 7],
    ]);

    expect($endpoint->backoffSeconds(1))->toBe(7);
    expect($endpoint->backoffSeconds(2))->toBe(7);
    expect($endpoint->backoffSeconds(5))->toBe(7);
});

it('computes linear backoff', function (): void {
    $endpoint = EndpointConfig::fromArray('p', 'e', [
        'url'   => 'x',
        'retry' => ['attempts' => 5, 'backoff' => 'linear', 'base_seconds' => 4],
    ]);

    expect($endpoint->backoffSeconds(1))->toBe(4);
    expect($endpoint->backoffSeconds(3))->toBe(12);
    expect($endpoint->backoffSeconds(5))->toBe(20);
});

it('computes exponential backoff', function (): void {
    $endpoint = EndpointConfig::fromArray('p', 'e', [
        'url'   => 'x',
        'retry' => ['attempts' => 5, 'backoff' => 'exponential', 'base_seconds' => 5],
    ]);

    expect($endpoint->backoffSeconds(1))->toBe(5);   // 5 * 2^0
    expect($endpoint->backoffSeconds(2))->toBe(10);  // 5 * 2^1
    expect($endpoint->backoffSeconds(3))->toBe(20);  // 5 * 2^2
    expect($endpoint->backoffSeconds(4))->toBe(40);  // 5 * 2^3
});

it('falls back to base seconds for unknown backoff strategies', function (): void {
    $endpoint = EndpointConfig::fromArray('p', 'e', [
        'url'   => 'x',
        'retry' => ['attempts' => 5, 'backoff' => 'wibble', 'base_seconds' => 9],
    ]);

    expect($endpoint->backoffSeconds(3))->toBe(9);
});

it('exposes maxAttempts', function (): void {
    $endpoint = EndpointConfig::fromArray('p', 'e', [
        'url'   => 'x',
        'retry' => ['attempts' => 12, 'backoff' => 'fixed', 'base_seconds' => 1],
    ]);

    expect($endpoint->maxAttempts())->toBe(12);
});
