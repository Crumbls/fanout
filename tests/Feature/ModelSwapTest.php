<?php

declare(strict_types=1);

use Crumbls\Fanout\Facades\Fanout;
use Crumbls\Fanout\Jobs\DispatchFanoutEventJob;
use Crumbls\Fanout\Models\FanoutEvent;
use Illuminate\Support\Facades\Bus;

class CustomFanoutEvent extends FanoutEvent
{
    /** Custom subclass for swap test. */
    public function customMarker(): string
    {
        return 'i-am-custom';
    }
}

it('honours a swapped event model class through config', function (): void {
    config()->set('fanout.models.event', CustomFanoutEvent::class);
    config()->set('fanout.profiles.acme', [
        'persist'   => 'full',
        'endpoints' => ['s' => ['url' => 'https://s', 'enabled' => true]],
    ]);

    Bus::fake([DispatchFanoutEventJob::class]);

    expect(Fanout::eventModel())->toBe(CustomFanoutEvent::class);

    $event = Fanout::dispatch('acme', ['type' => 'a']);

    expect($event)->toBeInstanceOf(CustomFanoutEvent::class);
    expect($event->customMarker())->toBe('i-am-custom');

    // Round-trip via the manager — the resolved class must still be the custom one.
    $reloaded = (Fanout::eventModel())::query()->find($event->getKey());
    expect($reloaded)->toBeInstanceOf(CustomFanoutEvent::class);
});
