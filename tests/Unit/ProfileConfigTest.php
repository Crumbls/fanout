<?php

declare(strict_types=1);

use Crumbls\Fanout\Support\ProfileConfig;

it('normalises the persist setting to a known value', function (): void {
    expect(ProfileConfig::fromArray('p', ['persist' => 'full',     'endpoints' => []])->persist)->toBe('full');
    expect(ProfileConfig::fromArray('p', ['persist' => 'metadata', 'endpoints' => []])->persist)->toBe('metadata');
    expect(ProfileConfig::fromArray('p', ['persist' => 'none',     'endpoints' => []])->persist)->toBe('none');
});

it('falls back to full persistence on garbage input', function (): void {
    $config = ProfileConfig::fromArray('p', ['persist' => 'wibble', 'endpoints' => []]);

    expect($config->persist)->toBe('full');
    expect($config->shouldPersist())->toBeTrue();
    expect($config->shouldStorePayload())->toBeTrue();
});

it('reports persistence behaviour for each mode', function (): void {
    $full     = ProfileConfig::fromArray('p', ['persist' => 'full', 'endpoints' => []]);
    $metadata = ProfileConfig::fromArray('p', ['persist' => 'metadata', 'endpoints' => []]);
    $none     = ProfileConfig::fromArray('p', ['persist' => 'none', 'endpoints' => []]);

    expect($full->shouldPersist())->toBeTrue();
    expect($full->shouldStorePayload())->toBeTrue();

    expect($metadata->shouldPersist())->toBeTrue();
    expect($metadata->shouldStorePayload())->toBeFalse();

    expect($none->shouldPersist())->toBeFalse();
    expect($none->shouldStorePayload())->toBeFalse();
});

it('parses endpoints into EndpointConfig instances and filters disabled ones', function (): void {
    $config = ProfileConfig::fromArray('stripe-prod', [
        'endpoints' => [
            'staging' => ['url' => 'https://s', 'enabled' => true],
            'dev'     => ['url' => 'https://d', 'enabled' => false],
            'qa'      => ['url' => 'https://q'], // defaults to enabled
        ],
    ]);

    expect($config->endpoints)->toHaveCount(3);
    expect(array_keys($config->enabledEndpoints()))->toEqualCanonicalizing(['staging', 'qa']);
    expect($config->endpoints['staging']->profileName)->toBe('stripe-prod');
});

it('defaults continueOnEndpointFailure to true', function (): void {
    $config = ProfileConfig::fromArray('p', ['endpoints' => []]);

    expect($config->continueOnEndpointFailure)->toBeTrue();
});

it('captures the inbound signature header for passthrough signing', function (): void {
    $config = ProfileConfig::fromArray('p', [
        'signature_header' => 'Stripe-Signature',
        'endpoints'        => [],
    ]);

    expect($config->signatureHeader)->toBe('Stripe-Signature');
});
