<?php

declare(strict_types=1);

use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Signers\HmacSha256Signer;
use Crumbls\Fanout\Signers\PassthroughSigner;
use Crumbls\Fanout\Support\EndpointConfig;

function endpointWith(array $config = []): EndpointConfig
{
    return EndpointConfig::fromArray('test-profile', 'staging', array_merge([
        'url' => 'https://staging.example.test',
    ], $config));
}

it('HmacSha256Signer produces the expected sha256 header', function (): void {
    $body   = '{"hello":"world"}';
    $secret = 'shh';
    $expected = hash_hmac('sha256', $body, $secret);

    $endpoint = endpointWith([
        'secret'           => $secret,
        'signature_header' => 'X-Fanout-Signature',
    ]);

    $headers = (new HmacSha256Signer())->sign($body, $endpoint, null);

    expect($headers)->toBe(['X-Fanout-Signature' => "sha256={$expected}"]);
});

it('HmacSha256Signer falls back to a default header name', function (): void {
    $endpoint = endpointWith(['secret' => 's']);

    $headers = (new HmacSha256Signer())->sign('body', $endpoint, null);

    expect(array_keys($headers))->toBe(['X-Fanout-Signature']);
});

it('HmacSha256Signer returns no headers when no secret is configured', function (): void {
    $endpoint = endpointWith();

    expect((new HmacSha256Signer())->sign('body', $endpoint, null))->toBe([]);
});

it('PassthroughSigner forwards the original signature header', function (): void {
    $event = new FanoutEvent;
    $event->signature = 't=1234,v1=abc';

    $endpoint = endpointWith(['signature_header' => 'X-Forwarded-Signature']);

    $headers = (new PassthroughSigner())->sign('body', $endpoint, $event);

    expect($headers)->toBe(['X-Forwarded-Signature' => 't=1234,v1=abc']);
});

it('PassthroughSigner emits no headers when the inbound event had no signature', function (): void {
    $event = new FanoutEvent;

    $headers = (new PassthroughSigner())->sign('body', endpointWith(), $event);

    expect($headers)->toBe([]);
});

it('PassthroughSigner emits no headers when there is no event at all (ephemeral mode)', function (): void {
    $headers = (new PassthroughSigner())->sign('body', endpointWith(), null);

    expect($headers)->toBe([]);
});
