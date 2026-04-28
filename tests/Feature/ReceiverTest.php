<?php

declare(strict_types=1);

use Crumbls\Fanout\Jobs\DeliverFanoutEventJob;
use Crumbls\Fanout\Jobs\DispatchFanoutEventJob;
use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Validators\HmacSha256SignatureValidator;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config()->set('fanout.profiles.test-prod', [
        'persist'          => 'full',
        'validator'        => HmacSha256SignatureValidator::class,
        'secret'           => 'shh',
        'signature_header' => 'X-Signature',
        'endpoints'        => [
            'staging' => [
                'url'     => 'https://example.test/staging',
                'enabled' => true,
            ],
        ],
    ]);
});

it('rejects a request with a bad signature and writes nothing', function (): void {
    $response = $this->postJson('/fanout/in/test-prod', ['hello' => 'world'], [
        'X-Signature' => 'nope',
    ]);

    $response->assertStatus(401);

    expect(FanoutEvent::query()->count())->toBe(0);
});

it('accepts a signed request, persists it, and dispatches the fan-out job', function (): void {
    Bus::fake([DispatchFanoutEventJob::class]);

    $body = json_encode(['type' => 'invoice.created', 'id' => 'in_1']);
    $sig  = hash_hmac('sha256', $body, 'shh');

    $response = $this->call(
        method: 'POST',
        uri: '/fanout/in/test-prod',
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

    expect(FanoutEvent::query()->count())->toBe(1);

    $event = FanoutEvent::query()->first();
    expect($event->profile)->toBe('test-prod');
    expect($event->event_type)->toBe('invoice.created');
    expect($event->payload)->toMatchArray(['type' => 'invoice.created', 'id' => 'in_1']);

    Bus::assertDispatched(DispatchFanoutEventJob::class);
});

it('skips persistence in persist=none mode and dispatches ephemeral jobs only', function (): void {
    config()->set('fanout.profiles.ephemeral', [
        'persist'  => 'none',
        'endpoints' => [
            'sink' => ['url' => 'https://example.test/sink', 'enabled' => true],
        ],
    ]);

    Bus::fake([DeliverFanoutEventJob::class]);

    $response = $this->postJson('/fanout/in/ephemeral', ['hello' => 'world']);

    $response->assertStatus(202);

    expect(FanoutEvent::query()->count())->toBe(0);
    Bus::assertDispatched(DeliverFanoutEventJob::class);
});
