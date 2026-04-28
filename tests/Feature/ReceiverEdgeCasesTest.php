<?php

declare(strict_types=1);

use Crumbls\Fanout\Jobs\DeliverFanoutEventJob;
use Crumbls\Fanout\Jobs\DispatchFanoutEventJob;
use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Validators\HmacSha256SignatureValidator;
use Illuminate\Support\Facades\Bus;

it('returns 404 for an unknown profile', function (): void {
    $response = $this->postJson('/fanout/in/no-such-profile', ['hello' => 'world']);

    $response->assertStatus(404);
    expect(FanoutEvent::query()->count())->toBe(0);
});

it('persist=metadata writes an event but no payload column, and carries the body in the dispatch job', function (): void {
    config()->set('fanout.profiles.audit', [
        'persist'   => 'metadata',
        'endpoints' => [
            'staging' => ['url' => 'https://staging.example.test', 'enabled' => true],
        ],
    ]);

    Bus::fake([DispatchFanoutEventJob::class]);

    $body = json_encode(['type' => 'audit.event', 'pii' => 'do-not-store']);

    $response = $this->call(
        method: 'POST',
        uri: '/fanout/in/audit',
        parameters: [],
        cookies: [],
        files: [],
        server: ['CONTENT_TYPE' => 'application/json'],
        content: $body,
    );

    $response->assertStatus(202);

    $event = FanoutEvent::query()->first();
    expect($event)->not->toBeNull();
    expect($event->event_type)->toBe('audit.event');
    expect($event->payload)->toBeNull();

    // The pii must be carried in the job payload override, not in the DB row.
    Bus::assertDispatched(DispatchFanoutEventJob::class, function (DispatchFanoutEventJob $job): bool {
        return ($job->payloadOverride['pii'] ?? null) === 'do-not-store';
    });
});

it('captures the original signature header into the event row', function (): void {
    config()->set('fanout.profiles.signed', [
        'persist'          => 'full',
        'validator'        => HmacSha256SignatureValidator::class,
        'secret'           => 'shh',
        'signature_header' => 'X-Signature',
        'endpoints'        => [],
    ]);

    Bus::fake([DispatchFanoutEventJob::class, DeliverFanoutEventJob::class]);

    $body = json_encode(['hello' => 'world']);
    $sig  = hash_hmac('sha256', $body, 'shh');

    $response = $this->call(
        method: 'POST',
        uri: '/fanout/in/signed',
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

    $event = FanoutEvent::query()->first();
    expect($event->signature)->toBe($sig);
});

it('falls back to a wrapped raw body when the request is not JSON', function (): void {
    config()->set('fanout.profiles.raw', [
        'persist'   => 'full',
        'endpoints' => [],
    ]);

    Bus::fake([DispatchFanoutEventJob::class]);

    $response = $this->call(
        method: 'POST',
        uri: '/fanout/in/raw',
        parameters: [],
        cookies: [],
        files: [],
        server: ['CONTENT_TYPE' => 'text/plain'],
        content: 'plain text body',
    );

    $response->assertStatus(202);

    $event = FanoutEvent::query()->first();
    expect($event->payload)->toBe(['_raw' => 'plain text body']);
});

it('accepts requests when no validator is configured (trusted internal source)', function (): void {
    config()->set('fanout.profiles.trusted', [
        'persist'   => 'full',
        'endpoints' => [],
    ]);

    Bus::fake([DispatchFanoutEventJob::class]);

    $response = $this->postJson('/fanout/in/trusted', ['hello' => 'world']);

    $response->assertStatus(202);
    expect(FanoutEvent::query()->count())->toBe(1);
});
