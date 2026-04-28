<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Jobs;

use Crumbls\Fanout\Fanout;
use Crumbls\Fanout\Models\FanoutDelivery;
use Crumbls\Fanout\Models\FanoutEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Fan a stored event out to one delivery job per enabled endpoint.
 *
 * Used for `persist: 'full'` (payload loaded from event row) and
 * `persist: 'metadata'` (payload carried in the job constructor because
 * the event row's payload column is null).
 *
 * Replay re-dispatches this job — optionally scoped to a single endpoint.
 */
class DispatchFanoutEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $eventId,
        public ?array $payloadOverride = null,
        public ?string $endpointFilter = null,
    ) {}

    public function handle(Fanout $fanout): void
    {
        /** @var FanoutEvent|null $event */
        $event = $fanout->eventModel()::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        $profile = $fanout->profile($event->profile);
        $payload = $this->payloadOverride ?? (array) ($event->payload ?? []);

        foreach ($profile->enabledEndpoints() as $endpoint) {
            if ($this->endpointFilter !== null && $endpoint->name !== $this->endpointFilter) {
                continue;
            }

            $delivery = $this->createDelivery($fanout, $event, $endpoint->name, $endpoint->environment);

            DeliverFanoutEventJob::dispatchForDelivery($delivery->getKey(), $payload);
        }
    }

    protected function createDelivery(
        Fanout $fanout,
        FanoutEvent $event,
        string $endpointName,
        ?string $environment,
    ): FanoutDelivery {
        $delivery = $fanout->newDelivery();

        $delivery->forceFill([
            'event_id'             => $event->getKey(),
            'endpoint_name'        => $endpointName,
            'endpoint_environment' => $environment,
            'status'               => FanoutDelivery::STATUS_PENDING,
            'attempts'             => 0,
            'purgeable_at'         => now()->addDays((int) config('fanout.pruning.keep_events_days', 30)),
        ])->save();

        return $delivery;
    }
}
