<?php

declare(strict_types=1);

use Crumbls\Fanout\Validators\GithubSignatureValidator;
use Crumbls\Fanout\Validators\HmacSha256SignatureValidator;
use Crumbls\Fanout\Validators\SpatieSignatureValidator;
use Crumbls\Fanout\Validators\StripeSignatureValidator;

it('verifies a generic HMAC SHA-256 signature', function (): void {
    $body   = '{"hello":"world"}';
    $secret = 'shh';
    $sig    = hash_hmac('sha256', $body, $secret);

    $valid = (new HmacSha256SignatureValidator())->verify(
        rawBody: $body,
        headers: ['X-Signature' => [$sig]],
        config:  ['secret' => $secret],
    );

    expect($valid)->toBeTrue();
});

it('rejects a bad HMAC signature', function (): void {
    $valid = (new HmacSha256SignatureValidator())->verify(
        rawBody: '{"a":1}',
        headers: ['X-Signature' => ['nope']],
        config:  ['secret' => 'shh'],
    );

    expect($valid)->toBeFalse();
});

it('verifies a GitHub-style sha256= signature', function (): void {
    $body   = '{"action":"opened"}';
    $secret = 'gh-secret';
    $sig    = 'sha256=' . hash_hmac('sha256', $body, $secret);

    $valid = (new GithubSignatureValidator())->verify(
        rawBody: $body,
        headers: ['X-Hub-Signature-256' => [$sig]],
        config:  ['secret' => $secret],
    );

    expect($valid)->toBeTrue();
});

it('verifies a Stripe-style timestamped signature', function (): void {
    $body   = '{"id":"evt_123"}';
    $secret = 'stripe-secret';
    $ts     = time();
    $sig    = hash_hmac('sha256', "{$ts}.{$body}", $secret);

    $valid = (new StripeSignatureValidator())->verify(
        rawBody: $body,
        headers: ['Stripe-Signature' => ["t={$ts},v1={$sig}"]],
        config:  ['secret' => $secret],
    );

    expect($valid)->toBeTrue();
});

it('rejects a Stripe signature outside the tolerance window', function (): void {
    $body   = '{"id":"evt_123"}';
    $secret = 'stripe-secret';
    $ts     = time() - 3600;
    $sig    = hash_hmac('sha256', "{$ts}.{$body}", $secret);

    $valid = (new StripeSignatureValidator())->verify(
        rawBody: $body,
        headers: ['Stripe-Signature' => ["t={$ts},v1={$sig}"]],
        config:  ['secret' => $secret, 'tolerance' => 300],
    );

    expect($valid)->toBeFalse();
});

it('verifies a Spatie webhook-client compatible signature', function (): void {
    $body   = '{"foo":"bar"}';
    $secret = 'spatie-secret';
    $sig    = hash_hmac('sha256', $body, $secret);

    $valid = (new SpatieSignatureValidator())->verify(
        rawBody: $body,
        headers: ['Signature' => [$sig]],
        config:  ['secret' => $secret],
    );

    expect($valid)->toBeTrue();
});
