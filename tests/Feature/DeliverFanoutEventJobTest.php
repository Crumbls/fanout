<?php

declare(strict_types=1);

use Crumbls\Fanout\Contracts\PayloadFilter;
use Crumbls\Fanout\Contracts\PayloadTransformer;
use Crumbls\Fanout\Fanout;
use Crumbls\Fanout\Jobs\DeliverFanoutEventJob;
use Crumbls\Fanout\Models\FanoutDelivery;
use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Signers\HmacSha256Signer;
use Crumbls\Fanout\Support\EndpointConfig;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class TestRejectAllFilter implements PayloadFilter
{
    public function shouldDeliver(array $payload, EndpointConfig $endpoint, ?FanoutEvent $event): bool
    {
        return false;
    }
}

class TestStripEmailTransformer implements PayloadTransformer
{
    public function transform(array $payload, EndpointConfig $endpoint, ?FanoutEvent $event): array
    {
        unset($payload['email']);

        return array_merge($payload, ['fanout_transformed' => true]);
    }
}

beforeEach(function (): void {
    config()->set('fanout.profiles.acme', [
        'persist'   => 'full',
        'endpoints' => [
            'staging' => [
                'url'         => 'https://staging.example.test/hooks',
                'enabled'     => true,
                'environment' => 'staging',
                'timeout'     => 5,
                'retry'       => ['attempts' => 3, 'backoff' => 'fixed', 'base_seconds' => 10],
                'headers'     => [
                    'X-Fanout-Source' => 'production',
                    'X-Fanout-Event'  => '{event.id}',
                    'X-Event-Type'    => '{event.type}',
                ],
            ],
        ],
    ]);
});

function createEventAndDelivery(array $payload = ['type' => 'invoice.paid', 'amount' => 100]): array
{
    $event = new FanoutEvent;
    $event->forceFill([
        'profile'     => 'acme',
        'event_type'  => $payload['type'] ?? null,
        'payload'     => $payload,
        'received_at' => now(),
    ])->save();

    $delivery = new FanoutDelivery;
    $delivery->forceFill([
        'event_id'      => $event->getKey(),
        'endpoint_name' => 'staging',
        'status'        => FanoutDelivery::STATUS_PENDING,
        'attempts'      => 0,
    ])->save();

    return [$event, $delivery];
}

it('marks delivery succeeded on a 2xx response and stores the response body', function (): void {
    Http::fake(['staging.example.test/*' => Http::response('{"ok":true}', 200)]);
    [, $delivery] = createEventAndDelivery();

    DeliverFanoutEventJob::dispatchForDelivery($delivery->getKey(), null);

    $delivery->refresh();

    expect($delivery->status)->toBe(FanoutDelivery::STATUS_SUCCEEDED);
    expect($delivery->last_status_code)->toBe(200);
    expect($delivery->last_response_body)->toBe('{"ok":true}');
    expect($delivery->attempts)->toBe(1);
    expect($delivery->completed_at)->not->toBeNull();
});

it('renders header templates with event tokens', function (): void {
    Http::fake(['staging.example.test/*' => Http::response('', 200)]);
    [$event, $delivery] = createEventAndDelivery();

    DeliverFanoutEventJob::dispatchForDelivery($delivery->getKey(), null);

    Http::assertSent(function ($request) use ($event): bool {
        return $request->header('X-Fanout-Source')[0] === 'production'
            && $request->header('X-Fanout-Event')[0]  === $event->getKey()
            && $request->header('X-Event-Type')[0]    === 'invoice.paid';
    });
});

it('reschedules a failed delivery without exhausting retries', function (): void {
    Http::fake(['staging.example.test/*' => Http::response('boom', 500)]);
    Bus::fake([DeliverFanoutEventJob::class]);

    [, $delivery] = createEventAndDelivery();

    (new DeliverFanoutEventJob($delivery->getKey(), null))->handle(app(Fanout::class));

    $delivery->refresh();
    expect($delivery->status)->toBe(FanoutDelivery::STATUS_PENDING);
    expect($delivery->attempts)->toBe(1);
    expect($delivery->last_status_code)->toBe(500);
    expect($delivery->next_attempt_at)->not->toBeNull();

    Bus::assertDispatched(DeliverFanoutEventJob::class, function (DeliverFanoutEventJob $job) use ($delivery): bool {
        return $job->deliveryId === $delivery->getKey();
    });
});

it('marks the delivery failed once retries are exhausted', function (): void {
    Http::fake(['staging.example.test/*' => Http::response('still bad', 502)]);
    Bus::fake([DeliverFanoutEventJob::class]);

    [, $delivery] = createEventAndDelivery();
    $delivery->forceFill(['attempts' => 2])->save(); // retry attempts=3, this push to 3

    (new DeliverFanoutEventJob($delivery->getKey(), null))->handle(app(Fanout::class));

    $delivery->refresh();
    expect($delivery->status)->toBe(FanoutDelivery::STATUS_FAILED);
    expect($delivery->attempts)->toBe(3);
    expect($delivery->last_status_code)->toBe(502);
    expect($delivery->last_response_body)->toBe('still bad');
    expect($delivery->completed_at)->not->toBeNull();
    expect($delivery->purgeable_at)->not->toBeNull();

    Bus::assertNotDispatched(DeliverFanoutEventJob::class);
});

it('captures network exceptions as failures with last_error set', function (): void {
    Http::fake(function (): void {
        throw new \Illuminate\Http\Client\ConnectionException('network is down');
    });
    Bus::fake([DeliverFanoutEventJob::class]);

    [, $delivery] = createEventAndDelivery();
    $delivery->forceFill(['attempts' => 2])->save();

    (new DeliverFanoutEventJob($delivery->getKey(), null))->handle(app(Fanout::class));

    $delivery->refresh();
    expect($delivery->status)->toBe(FanoutDelivery::STATUS_FAILED);
    expect($delivery->last_error)->toContain('network is down');
});

it('skips delivery when the filter rejects the payload', function (): void {
    config()->set('fanout.profiles.acme.endpoints.staging.filter', TestRejectAllFilter::class);
    Http::fake();

    [, $delivery] = createEventAndDelivery();

    (new DeliverFanoutEventJob($delivery->getKey(), null))->handle(app(Fanout::class));

    $delivery->refresh();
    expect($delivery->status)->toBe(FanoutDelivery::STATUS_SKIPPED);
    expect($delivery->attempts)->toBe(0);

    Http::assertNothingSent();
});

it('applies a transformer before sending', function (): void {
    config()->set('fanout.profiles.acme.endpoints.staging.transform', TestStripEmailTransformer::class);
    Http::fake(['staging.example.test/*' => Http::response('', 200)]);

    [, $delivery] = createEventAndDelivery(['type' => 'user.created', 'email' => 'private@example.test', 'name' => 'Jane']);

    (new DeliverFanoutEventJob($delivery->getKey(), null))->handle(app(Fanout::class));

    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);

        return ! array_key_exists('email', $body)
            && $body['fanout_transformed'] === true
            && $body['name'] === 'Jane';
    });

    $delivery->refresh();
    expect($delivery->request_payload)->not->toHaveKey('email');
});

it('attaches the HMAC signature header from the configured signer', function (): void {
    config()->set('fanout.profiles.acme.endpoints.staging.signer', HmacSha256Signer::class);
    config()->set('fanout.profiles.acme.endpoints.staging.secret', 'staging-secret');
    config()->set('fanout.profiles.acme.endpoints.staging.signature_header', 'X-Fanout-Signature');

    Http::fake(['staging.example.test/*' => Http::response('', 200)]);

    [, $delivery] = createEventAndDelivery(['type' => 'a', 'b' => 1]);

    (new DeliverFanoutEventJob($delivery->getKey(), null))->handle(app(Fanout::class));

    Http::assertSent(function ($request): bool {
        $header = $request->header('X-Fanout-Signature')[0] ?? null;

        return $header !== null
            && str_starts_with($header, 'sha256=')
            && $header === 'sha256=' . hash_hmac('sha256', $request->body(), 'staging-secret');
    });
});

it('reschedules without consuming an attempt when rate limited', function (): void {
    config()->set('fanout.profiles.acme.endpoints.staging.rate_limit', ['per_minute' => 1]);
    Http::fake();
    Bus::fake([DeliverFanoutEventJob::class]);

    [, $delivery] = createEventAndDelivery();

    // Saturate the rate limit by hand so the next attempt trips the check.
    $endpointKey = 'fanout:acme:staging';
    RateLimiter::hit($endpointKey, 60);

    (new DeliverFanoutEventJob($delivery->getKey(), null))->handle(app(Fanout::class));

    $delivery->refresh();
    expect($delivery->status)->toBe(FanoutDelivery::STATUS_PENDING);
    expect($delivery->attempts)->toBe(0); // never sent, never counted
    expect($delivery->next_attempt_at)->not->toBeNull();

    Http::assertNothingSent();
    Bus::assertDispatched(DeliverFanoutEventJob::class);
});

it('skips delivery when the endpoint has been disabled in config', function (): void {
    Http::fake();
    [, $delivery] = createEventAndDelivery();

    config()->set('fanout.profiles.acme.endpoints.staging.enabled', false);

    (new DeliverFanoutEventJob($delivery->getKey(), null))->handle(app(Fanout::class));

    $delivery->refresh();
    expect($delivery->status)->toBe(FanoutDelivery::STATUS_SKIPPED);
    expect($delivery->last_error)->toContain('disabled');

    Http::assertNothingSent();
});

it('short-circuits when the delivery is already terminal', function (): void {
    Http::fake();
    [, $delivery] = createEventAndDelivery();
    $delivery->forceFill(['status' => FanoutDelivery::STATUS_SUCCEEDED, 'completed_at' => now()])->save();

    (new DeliverFanoutEventJob($delivery->getKey(), null))->handle(app(Fanout::class));

    Http::assertNothingSent();
    expect($delivery->refresh()->status)->toBe(FanoutDelivery::STATUS_SUCCEEDED);
});

it('returns silently when the delivery row has been deleted', function (): void {
    Http::fake();

    (new DeliverFanoutEventJob('does-not-exist', null))->handle(app(Fanout::class));

    Http::assertNothingSent();
});

// Ephemeral mode (persist=none) -----------------------------------------------

it('ephemeral mode delivers without writing any rows', function (): void {
    config()->set('fanout.profiles.acme.persist', 'none');
    Http::fake(['staging.example.test/*' => Http::response('', 200)]);

    (new DeliverFanoutEventJob(
        deliveryId:      null,
        payload:         ['type' => 'invoice.paid', 'amount' => 1],
        profileName:     'acme',
        endpointName:    'staging',
        originalHeaders: [],
    ))->handle(app(Fanout::class));

    expect(FanoutEvent::query()->count())->toBe(0);
    expect(FanoutDelivery::query()->count())->toBe(0);
    Http::assertSent(fn ($request) => $request->url() === 'https://staging.example.test/hooks');
});

it('ephemeral mode throws on a non-2xx response so it lands in failed_jobs', function (): void {
    config()->set('fanout.profiles.acme.persist', 'none');
    Http::fake(['staging.example.test/*' => Http::response('', 500)]);

    expect(fn () => (new DeliverFanoutEventJob(
        deliveryId:      null,
        payload:         ['type' => 'x'],
        profileName:     'acme',
        endpointName:    'staging',
        originalHeaders: [],
    ))->handle(app(Fanout::class)))->toThrow(\RuntimeException::class);
});
