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
            'staging' => ['url' => 'https://s', 'enabled' => true],
        ],
    ]);
});

it('fanout:replay queues a fresh dispatch job for the given event', function (): void {
    Bus::fake([DispatchFanoutEventJob::class]);

    $event = new FanoutEvent;
    $event->forceFill(['profile' => 'acme', 'received_at' => now()])->save();

    $this->artisan('fanout:replay', ['event' => $event->getKey()])
        ->expectsOutputToContain("Replaying event [{$event->getKey()}]")
        ->expectsOutputToContain('Replay queued')
        ->assertExitCode(0);

    Bus::assertDispatched(DispatchFanoutEventJob::class, fn ($job) => $job->eventId === $event->getKey());
});

it('fanout:replay errors cleanly for an unknown event id', function (): void {
    $this->artisan('fanout:replay', ['event' => 'does-not-exist'])
        ->expectsOutputToContain('Event [does-not-exist] not found')
        ->assertExitCode(1);
});

it('fanout:replay-failed reports how many deliveries it requeued', function (): void {
    Bus::fake([DeliverFanoutEventJob::class]);

    $event = new FanoutEvent;
    $event->forceFill(['profile' => 'acme', 'received_at' => now()])->save();

    foreach (
        [
            FanoutDelivery::STATUS_FAILED,
            FanoutDelivery::STATUS_FAILED,
            FanoutDelivery::STATUS_SUCCEEDED,
        ] as $status
    ) {
        $delivery = new FanoutDelivery;
        $delivery->forceFill([
            'event_id'      => $event->getKey(),
            'endpoint_name' => 'staging',
            'status'        => $status,
        ])->save();
    }

    $this->artisan('fanout:replay-failed')
        ->expectsOutputToContain('Re-queued 2 delivery jobs')
        ->assertExitCode(0);
});

it('fanout:purge removes rows past their purgeable_at and reports the counts', function (): void {
    $expiredEvent = new FanoutEvent;
    $expiredEvent->forceFill([
        'profile'      => 'acme',
        'received_at'  => now()->subDays(60),
        'purgeable_at' => now()->subDay(),
    ])->save();

    $expiredDelivery = new FanoutDelivery;
    $expiredDelivery->forceFill([
        'event_id'      => $expiredEvent->getKey(),
        'endpoint_name' => 'staging',
        'status'        => FanoutDelivery::STATUS_SUCCEEDED,
        'purgeable_at'  => now()->subDay(),
    ])->save();

    $freshEvent = new FanoutEvent;
    $freshEvent->forceFill([
        'profile'      => 'acme',
        'received_at'  => now(),
        'purgeable_at' => now()->addDays(30),
    ])->save();

    $this->artisan('fanout:purge')
        ->expectsOutputToContain('Purged 1 event(s)')
        ->assertExitCode(0);

    expect(FanoutEvent::query()->find($expiredEvent->getKey()))->toBeNull();
    expect(FanoutEvent::query()->find($freshEvent->getKey()))->not->toBeNull();
    expect(FanoutDelivery::query()->find($expiredDelivery->getKey()))->toBeNull();
});

it('fanout:purge --dry-run leaves rows in place', function (): void {
    $event = new FanoutEvent;
    $event->forceFill([
        'profile'      => 'acme',
        'received_at'  => now(),
        'purgeable_at' => now()->subDay(),
    ])->save();

    $this->artisan('fanout:purge', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect(FanoutEvent::query()->find($event->getKey()))->not->toBeNull();
});

it('fanout:purge respects the pruning.enabled config flag', function (): void {
    config()->set('fanout.pruning.enabled', false);

    $event = new FanoutEvent;
    $event->forceFill([
        'profile'      => 'acme',
        'received_at'  => now(),
        'purgeable_at' => now()->subDay(),
    ])->save();

    $this->artisan('fanout:purge')
        ->expectsOutputToContain('Pruning disabled')
        ->assertExitCode(0);

    expect(FanoutEvent::query()->find($event->getKey()))->not->toBeNull();
});
