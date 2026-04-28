<?php

declare(strict_types=1);

use Crumbls\Fanout\Facades\Fanout;
use Crumbls\Fanout\Jobs\DeliverFanoutEventJob;
use Crumbls\Fanout\Jobs\DispatchFanoutEventJob;
use Crumbls\Fanout\Models\FanoutDelivery;
use Crumbls\Fanout\Models\FanoutEvent;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config()->set('fanout.profiles.acme', [
        'persist'   => 'full',
        'endpoints' => [
            'staging' => ['url' => 'https://staging.example.test', 'enabled' => true],
            'dev'     => ['url' => 'https://dev.example.test',     'enabled' => true],
        ],
    ]);
});

it('throws on unknown profile lookup', function (): void {
    expect(fn () => Fanout::dispatch('not-a-profile', []))->toThrow(InvalidArgumentException::class);
});

it('programmatic dispatch creates an event and queues the fan-out job', function (): void {
    Bus::fake([DispatchFanoutEventJob::class]);

    $event = Fanout::dispatch('acme', ['type' => 'order.created', 'id' => 42]);

    expect($event)->toBeInstanceOf(FanoutEvent::class);
    expect($event->profile)->toBe('acme');
    expect($event->event_type)->toBe('order.created');
    expect($event->payload)->toBe(['type' => 'order.created', 'id' => 42]);

    Bus::assertDispatched(DispatchFanoutEventJob::class, fn ($job) => $job->eventId === $event->getKey());
});

it('replay reuses an existing event id and dispatches a fresh fan-out job', function (): void {
    Bus::fake([DispatchFanoutEventJob::class]);

    $event = new FanoutEvent;
    $event->forceFill([
        'profile'     => 'acme',
        'event_type'  => 't',
        'payload'     => ['type' => 't'],
        'received_at' => now(),
    ])->save();

    Fanout::replay($event);

    Bus::assertDispatched(DispatchFanoutEventJob::class, function ($job) use ($event): bool {
        return $job->eventId === $event->getKey() && $job->endpointFilter === null;
    });
});

it('replay accepts an endpoint filter', function (): void {
    Bus::fake([DispatchFanoutEventJob::class]);

    $event = new FanoutEvent;
    $event->forceFill([
        'profile'     => 'acme',
        'received_at' => now(),
    ])->save();

    Fanout::replay($event, 'dev');

    Bus::assertDispatched(DispatchFanoutEventJob::class, fn ($job) => $job->endpointFilter === 'dev');
});

it('replayFailed only requeues failed deliveries', function (): void {
    Bus::fake([DeliverFanoutEventJob::class]);

    $event = new FanoutEvent;
    $event->forceFill(['profile' => 'acme', 'received_at' => now()])->save();

    foreach (
        [
            ['endpoint_name' => 'staging', 'status' => FanoutDelivery::STATUS_FAILED],
            ['endpoint_name' => 'dev',     'status' => FanoutDelivery::STATUS_SUCCEEDED],
        ] as $row
    ) {
        $delivery = new FanoutDelivery;
        $delivery->forceFill(array_merge(['event_id' => $event->getKey(), 'attempts' => 5], $row))->save();
    }

    $count = Fanout::replayFailed();

    expect($count)->toBe(1);
    Bus::assertDispatchedTimes(DeliverFanoutEventJob::class, 1);

    $failed = FanoutDelivery::query()->where('endpoint_name', 'staging')->first();
    expect($failed->status)->toBe(FanoutDelivery::STATUS_PENDING);
    expect($failed->attempts)->toBe(0);
});

it('replayFailed scopes by profile when supplied', function (): void {
    Bus::fake([DeliverFanoutEventJob::class]);

    config()->set('fanout.profiles.beta', [
        'persist'   => 'full',
        'endpoints' => ['x' => ['url' => 'https://b', 'enabled' => true]],
    ]);

    $eventA = new FanoutEvent;
    $eventA->forceFill(['profile' => 'acme', 'received_at' => now()])->save();

    $eventB = new FanoutEvent;
    $eventB->forceFill(['profile' => 'beta', 'received_at' => now()])->save();

    foreach ([$eventA, $eventB] as $event) {
        $d = new FanoutDelivery;
        $d->forceFill([
            'event_id'      => $event->getKey(),
            'endpoint_name' => 'staging',
            'status'        => FanoutDelivery::STATUS_FAILED,
        ])->save();
    }

    $count = Fanout::replayFailed(profile: 'beta');

    expect($count)->toBe(1);
    Bus::assertDispatchedTimes(DeliverFanoutEventJob::class, 1);
});

it('exposes swappable model class names through the manager', function (): void {
    expect(Fanout::eventModel())->toBe(FanoutEvent::class);
    expect(Fanout::deliveryModel())->toBe(FanoutDelivery::class);
});
