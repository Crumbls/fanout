<?php

declare(strict_types=1);

use Crumbls\Fanout\Jobs\DeliverFanoutEventJob;
use Crumbls\Fanout\Jobs\DispatchFanoutEventJob;
use Crumbls\Fanout\Models\FanoutDelivery;
use Crumbls\Fanout\Models\FanoutEvent;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config()->set('fanout.profiles.acme', [
        'persist'   => 'full',
        'endpoints' => [
            'staging' => ['url' => 'https://staging.example.test', 'enabled' => true,  'environment' => 'staging'],
            'dev'     => ['url' => 'https://dev.example.test',     'enabled' => true,  'environment' => 'dev'],
            'qa'      => ['url' => 'https://qa.example.test',      'enabled' => false, 'environment' => 'qa'],
        ],
    ]);
});

function makeEvent(array $payload = ['type' => 'invoice.created']): FanoutEvent
{
    $event = new FanoutEvent;
    $event->forceFill([
        'profile'      => 'acme',
        'event_type'   => $payload['type'] ?? null,
        'payload'      => $payload,
        'headers'      => ['content-type' => ['application/json']],
        'received_at'  => now(),
        'is_test'      => false,
    ])->save();

    return $event;
}

it('creates one pending delivery row per enabled endpoint', function (): void {
    Bus::fake([DeliverFanoutEventJob::class]);
    $event = makeEvent();

    (new DispatchFanoutEventJob($event->getKey()))->handle(app(\Crumbls\Fanout\Fanout::class));

    $deliveries = FanoutDelivery::query()->where('event_id', $event->getKey())->get();

    expect($deliveries)->toHaveCount(2);
    expect($deliveries->pluck('endpoint_name')->all())->toEqualCanonicalizing(['staging', 'dev']);
    expect($deliveries->every(fn ($d) => $d->status === FanoutDelivery::STATUS_PENDING))->toBeTrue();
    expect($deliveries->every(fn ($d) => $d->attempts === 0))->toBeTrue();

    Bus::assertDispatchedTimes(DeliverFanoutEventJob::class, 2);
});

it('only dispatches the requested endpoint when an endpoint filter is set', function (): void {
    Bus::fake([DeliverFanoutEventJob::class]);
    $event = makeEvent();

    (new DispatchFanoutEventJob(eventId: $event->getKey(), endpointFilter: 'staging'))
        ->handle(app(\Crumbls\Fanout\Fanout::class));

    $deliveries = FanoutDelivery::query()->where('event_id', $event->getKey())->get();

    expect($deliveries)->toHaveCount(1);
    expect($deliveries->first()->endpoint_name)->toBe('staging');

    Bus::assertDispatchedTimes(DeliverFanoutEventJob::class, 1);
});

it('records the endpoint environment on the delivery row', function (): void {
    Bus::fake([DeliverFanoutEventJob::class]);
    $event = makeEvent();

    (new DispatchFanoutEventJob($event->getKey()))->handle(app(\Crumbls\Fanout\Fanout::class));

    $staging = FanoutDelivery::query()->where('endpoint_name', 'staging')->first();
    expect($staging->endpoint_environment)->toBe('staging');
});

it('returns silently when the event no longer exists', function (): void {
    Bus::fake([DeliverFanoutEventJob::class]);

    (new DispatchFanoutEventJob('does-not-exist'))->handle(app(\Crumbls\Fanout\Fanout::class));

    Bus::assertNotDispatched(DeliverFanoutEventJob::class);
    expect(FanoutDelivery::query()->count())->toBe(0);
});

it('uses the carried payload when payloadOverride is supplied (metadata mode)', function (): void {
    Bus::fake([DeliverFanoutEventJob::class]);

    // Simulate metadata mode: event row exists but payload is null in DB.
    $event = new FanoutEvent;
    $event->forceFill([
        'profile'     => 'acme',
        'event_type'  => 'invoice.created',
        'payload'     => null,
        'received_at' => now(),
    ])->save();

    (new DispatchFanoutEventJob(
        eventId: $event->getKey(),
        payloadOverride: ['type' => 'invoice.created', 'mirrored' => true],
    ))->handle(app(\Crumbls\Fanout\Fanout::class));

    Bus::assertDispatched(DeliverFanoutEventJob::class, function (DeliverFanoutEventJob $job): bool {
        return $job->payload === ['type' => 'invoice.created', 'mirrored' => true];
    });
});
