<?php

declare(strict_types=1);

use Crumbls\Fanout\Models\FanoutDelivery;
use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Signers\HmacSha256Signer;
use Crumbls\Fanout\Validators\HmacSha256SignatureValidator;
use Illuminate\Support\Facades\Http;

/**
 * Full pipeline test: HTTP receiver -> DispatchFanoutEventJob ->
 * DeliverFanoutEventJob -> outbound HTTP. Runs synchronously because the
 * test environment uses the sync queue. This locks in the entire chain.
 */
it('routes an inbound webhook all the way to two outbound destinations', function (): void {
    config()->set('fanout.profiles.acme', [
        'persist'          => 'full',
        'validator'        => HmacSha256SignatureValidator::class,
        'secret'           => 'inbound-secret',
        'signature_header' => 'X-Signature',
        'endpoints'        => [
            'staging' => [
                'url'              => 'https://staging.example.test/hooks',
                'enabled'          => true,
                'environment'      => 'staging',
                'signer'           => HmacSha256Signer::class,
                'secret'           => 'staging-secret',
                'signature_header' => 'X-Fanout-Signature',
                'headers'          => ['X-Fanout-Source' => 'production'],
            ],
            'dev' => [
                'url'         => 'https://dev.example.test/hooks',
                'enabled'     => true,
                'environment' => 'dev',
            ],
        ],
    ]);

    Http::fake([
        'staging.example.test/*' => Http::response('', 200),
        'dev.example.test/*'     => Http::response('', 200),
    ]);

    $body = json_encode(['type' => 'invoice.paid', 'id' => 'in_42', 'amount' => 99]);
    $sig  = hash_hmac('sha256', $body, 'inbound-secret');

    $response = $this->call(
        method: 'POST',
        uri: '/fanout/in/acme',
        parameters: [],
        cookies: [],
        files: [],
        server: [
            'CONTENT_TYPE'    => 'application/json',
            'HTTP_X_SIGNATURE' => $sig,
        ],
        content: $body,
    );

    $response->assertStatus(202);

    // One event row, two delivery rows, both succeeded.
    expect(FanoutEvent::query()->count())->toBe(1);

    $deliveries = FanoutDelivery::query()->get();
    expect($deliveries)->toHaveCount(2);

    foreach ($deliveries as $delivery) {
        expect($delivery->status)->toBe(FanoutDelivery::STATUS_SUCCEEDED);
        expect($delivery->last_status_code)->toBe(200);
    }

    // Both destinations actually received the request, with the staging
    // endpoint also carrying a re-signed HMAC under its own secret.
    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://staging.example.test/hooks'
            && $request->header('X-Fanout-Source')[0] === 'production'
            && str_starts_with($request->header('X-Fanout-Signature')[0] ?? '', 'sha256=');
    });

    Http::assertSent(fn ($request) => $request->url() === 'https://dev.example.test/hooks');
});
