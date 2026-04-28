<?php

declare(strict_types=1);

namespace Crumbls\Fanout;

use Closure;
use Crumbls\Fanout\Jobs\DispatchFanoutEventJob;
use Crumbls\Fanout\Models\FanoutDelivery;
use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Support\ProfileConfig;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class Fanout
{
    /** @var array<string, Closure> */
    protected array $validatorFactories = [];

    /** @var array<string, Closure> */
    protected array $signerFactories = [];

    /** @var array<string, Closure> */
    protected array $transformerFactories = [];

    /** @var array<string, Closure> */
    protected array $filterFactories = [];

    public function __construct(protected Container $container) {}

    /**
     * Resolve the Eloquent class used to persist incoming events.
     *
     * @return class-string<FanoutEvent>
     */
    public function eventModel(): string
    {
        return config('fanout.models.event', FanoutEvent::class);
    }

    /**
     * @return class-string<FanoutDelivery>
     */
    public function deliveryModel(): string
    {
        return config('fanout.models.delivery', FanoutDelivery::class);
    }

    public function newEvent(): FanoutEvent
    {
        $class = $this->eventModel();

        return new $class;
    }

    public function newDelivery(): FanoutDelivery
    {
        $class = $this->deliveryModel();

        return new $class;
    }

    /**
     * Programmatically inject an event into the fanout pipeline as if a
     * receiver had accepted it. Useful for tests, internal events, and
     * one-off backfills.
     */
    public function dispatch(string $profile, array $payload, array $headers = []): FanoutEvent
    {
        // Validate the profile exists.
        $this->profile($profile);

        $event = $this->newEvent();
        $event->forceFill([
            'profile'        => $profile,
            'event_type'     => $payload['type'] ?? null,
            'schema_version' => $payload['schema_version'] ?? null,
            'is_test'        => isset($payload['livemode']) ? ! (bool) $payload['livemode'] : false,
            'headers'        => $headers,
            'payload'        => $payload,
            'signature'      => null,
            'received_at'    => now(),
            'purgeable_at'   => $this->purgeableAt(false),
        ])->save();

        DispatchFanoutEventJob::dispatch($event->getKey())
            ->onConnection(config('fanout.queue.connection'))
            ->onQueue(config('fanout.queue.queue'));

        return $event;
    }

    /**
     * Re-run delivery for an event. If $endpoint is supplied, only that
     * endpoint is replayed; otherwise every configured endpoint runs again.
     */
    public function replay(FanoutEvent|string $event, ?string $endpoint = null): void
    {
        $event = $event instanceof FanoutEvent
            ? $event
            : $this->eventModel()::query()->findOrFail($event);

        DispatchFanoutEventJob::dispatch($event->getKey(), null, $endpoint)
            ->onConnection(config('fanout.queue.connection'))
            ->onQueue(config('fanout.queue.queue'));
    }

    /**
     * Replay every failed delivery, optionally scoped by profile / endpoint.
     */
    public function replayFailed(?string $profile = null, ?string $endpoint = null): int
    {
        $deliveryClass = $this->deliveryModel();
        $eventClass    = $this->eventModel();

        $query = $deliveryClass::query()
            ->where('status', FanoutDelivery::STATUS_FAILED)
            ->when($endpoint, fn ($q) => $q->where('endpoint_name', $endpoint))
            ->when($profile, fn ($q) => $q->whereIn(
                'event_id',
                $eventClass::query()->where('profile', $profile)->select('id'),
            ));

        $count = 0;

        $query->each(function (FanoutDelivery $delivery) use (&$count): void {
            $delivery->forceFill([
                'status'          => FanoutDelivery::STATUS_PENDING,
                'next_attempt_at' => null,
                'last_error'      => null,
                'attempts'        => 0,
            ])->save();

            \Crumbls\Fanout\Jobs\DeliverFanoutEventJob::dispatchForDelivery($delivery->getKey());

            $count++;
        });

        return $count;
    }

    public function profile(string $name): ProfileConfig
    {
        $raw = config("fanout.profiles.{$name}");

        if (! is_array($raw)) {
            throw new InvalidArgumentException("Unknown fanout profile: {$name}");
        }

        return ProfileConfig::fromArray($name, $raw);
    }

    public function hasProfile(string $name): bool
    {
        return is_array(config("fanout.profiles.{$name}"));
    }

    public function extendValidator(string $name, Closure $factory): void
    {
        $this->validatorFactories[$name] = $factory;
    }

    public function extendSigner(string $name, Closure $factory): void
    {
        $this->signerFactories[$name] = $factory;
    }

    public function extendTransformer(string $name, Closure $factory): void
    {
        $this->transformerFactories[$name] = $factory;
    }

    public function extendFilter(string $name, Closure $factory): void
    {
        $this->filterFactories[$name] = $factory;
    }

    public function resolveExtension(string $kind, string $name): ?object
    {
        $bag = match ($kind) {
            'validator'   => $this->validatorFactories,
            'signer'      => $this->signerFactories,
            'transformer' => $this->transformerFactories,
            'filter'      => $this->filterFactories,
            default       => [],
        };

        if (! isset($bag[$name])) {
            return null;
        }

        return $bag[$name]($this->container);
    }

    protected function purgeableAt(bool $failed): \DateTimeInterface
    {
        $days = $failed
            ? (int) config('fanout.pruning.keep_failed_events_days', 90)
            : (int) config('fanout.pruning.keep_events_days', 30);

        return now()->addDays($days);
    }
}
