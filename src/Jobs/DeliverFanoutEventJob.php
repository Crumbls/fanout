<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Jobs;

use Crumbls\Fanout\Contracts\PayloadFilter;
use Crumbls\Fanout\Contracts\PayloadTransformer;
use Crumbls\Fanout\Contracts\SignatureSigner;
use Crumbls\Fanout\Fanout;
use Crumbls\Fanout\Models\FanoutDelivery;
use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Support\EndpointConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * One outbound HTTP delivery to a single endpoint. Each invocation is a
 * single attempt — retries are scheduled by re-dispatching a fresh copy of
 * this job with a delay, rather than relying on Laravel's automatic retry.
 * That keeps the attempt counter on the delivery row authoritative and lets
 * us short-circuit cleanly on rate-limit hits without consuming an attempt.
 *
 * Two modes:
 *  - persisted: a fanout_deliveries row tracks state. Used in 'full' and
 *    'metadata' persist modes. Use ::forDelivery().
 *  - ephemeral: no DB row, Laravel's failed_jobs is the audit trail. Used
 *    in 'none' persist mode. Use ::ephemeral().
 */
class DeliverFanoutEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public ?string $deliveryId,
        public ?array $payload,
        public ?string $profileName = null,
        public ?string $endpointName = null,
        public ?array $originalHeaders = null,
    ) {}

    public static function dispatchForDelivery(string $deliveryId, ?array $payload = null): \Illuminate\Foundation\Bus\PendingDispatch
    {
        return static::dispatch($deliveryId, $payload)
            ->onConnection(config('fanout.queue.connection'))
            ->onQueue(config('fanout.queue.queue'));
    }

    public static function dispatchEphemeral(
        string $profileName,
        string $endpointName,
        array $payload,
        array $originalHeaders,
    ): \Illuminate\Foundation\Bus\PendingDispatch {
        return static::dispatch(null, $payload, $profileName, $endpointName, $originalHeaders)
            ->onConnection(config('fanout.queue.connection'))
            ->onQueue(config('fanout.queue.queue'));
    }

    public function handle(Fanout $fanout): void
    {
        $this->deliveryId !== null
            ? $this->handlePersisted($fanout)
            : $this->handleEphemeral($fanout);
    }

    protected function handlePersisted(Fanout $fanout): void
    {
        /** @var FanoutDelivery|null $delivery */
        $delivery = $fanout->deliveryModel()::query()->find($this->deliveryId);

        if ($delivery === null || $delivery->isTerminal()) {
            return;
        }

        /** @var FanoutEvent $event */
        $event   = $delivery->event;
        $profile = $fanout->profile($event->profile);
        $endpoint = $profile->endpoints[$delivery->endpoint_name] ?? null;

        if ($endpoint === null || ! $endpoint->enabled) {
            $delivery->forceFill([
                'status'       => FanoutDelivery::STATUS_SKIPPED,
                'completed_at' => now(),
                'last_error'   => 'Endpoint disabled or removed from config',
            ])->save();

            return;
        }

        $payload = $this->payload ?? (array) ($event->payload ?? []);

        if (! $this->passesFilter($endpoint, $payload, $event)) {
            $delivery->forceFill([
                'status'       => FanoutDelivery::STATUS_SKIPPED,
                'completed_at' => now(),
            ])->save();

            return;
        }

        $payload = $this->applyTransform($endpoint, $payload, $event);

        if ($this->throttled($endpoint)) {
            $this->reschedule(
                delivery: $delivery,
                endpoint: $endpoint,
                delaySeconds: max(1, RateLimiter::availableIn($endpoint->rateLimiterKey())),
            );

            return;
        }

        $body    = $this->encodePayload($payload);
        $headers = $this->buildHeaders($endpoint, $event, $body, $payload);

        $delivery->forceFill([
            'status'          => FanoutDelivery::STATUS_IN_FLIGHT,
            'attempts'        => $delivery->attempts + 1,
            'request_headers' => $headers,
            'request_payload' => $payload,
        ])->save();

        try {
            $response = Http::withHeaders($headers)
                ->timeout($endpoint->timeout)
                ->withBody($body, $headers['Content-Type'] ?? 'application/json')
                ->post($endpoint->url);

            $status = $response->status();
            $respBody = $this->truncate((string) $response->body());

            if ($response->successful()) {
                $delivery->forceFill([
                    'status'             => FanoutDelivery::STATUS_SUCCEEDED,
                    'last_status_code'   => $status,
                    'last_response_body' => $respBody,
                    'last_error'         => null,
                    'completed_at'       => now(),
                    'next_attempt_at'    => null,
                ])->save();

                return;
            }

            $this->failOrRetry($delivery, $endpoint, $status, $respBody, null);
        } catch (Throwable $e) {
            $this->failOrRetry($delivery, $endpoint, null, null, $e);
        }
    }

    protected function handleEphemeral(Fanout $fanout): void
    {
        $profile  = $fanout->profile((string) $this->profileName);
        $endpoint = $profile->endpoints[(string) $this->endpointName] ?? null;
        $payload  = (array) $this->payload;

        if ($endpoint === null || ! $endpoint->enabled) {
            return;
        }

        if (! $this->passesFilter($endpoint, $payload, null)) {
            return;
        }

        $payload = $this->applyTransform($endpoint, $payload, null);

        if ($this->throttled($endpoint)) {
            static::dispatchEphemeral(
                (string) $this->profileName,
                (string) $this->endpointName,
                $payload,
                (array) $this->originalHeaders,
            )->delay(max(1, RateLimiter::availableIn($endpoint->rateLimiterKey())));

            return;
        }

        $body    = $this->encodePayload($payload);
        $headers = $this->buildHeaders($endpoint, null, $body, $payload);

        $response = Http::withHeaders($headers)
            ->timeout($endpoint->timeout)
            ->withBody($body, $headers['Content-Type'] ?? 'application/json')
            ->post($endpoint->url);

        if (! $response->successful()) {
            // Surface to failed_jobs so ephemeral mode still has an audit trail.
            throw new \RuntimeException(
                "Fanout ephemeral delivery to [{$endpoint->name}] failed with status {$response->status()}",
            );
        }
    }

    protected function passesFilter(EndpointConfig $endpoint, array $payload, ?FanoutEvent $event): bool
    {
        if ($endpoint->filter === null) {
            return true;
        }

        /** @var PayloadFilter $filter */
        $filter = app($endpoint->filter);

        return $filter->shouldDeliver($payload, $endpoint, $event);
    }

    protected function applyTransform(EndpointConfig $endpoint, array $payload, ?FanoutEvent $event): array
    {
        if ($endpoint->transform === null) {
            return $payload;
        }

        /** @var PayloadTransformer $transformer */
        $transformer = app($endpoint->transform);

        return $transformer->transform($payload, $endpoint, $event);
    }

    protected function throttled(EndpointConfig $endpoint): bool
    {
        if ($endpoint->rateLimit === null) {
            return false;
        }

        $perMinute = (int) ($endpoint->rateLimit['per_minute'] ?? 0);

        if ($perMinute <= 0) {
            return false;
        }

        $key = $endpoint->rateLimiterKey();

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    protected function buildHeaders(
        EndpointConfig $endpoint,
        ?FanoutEvent $event,
        string $rawBody,
        array $payload,
    ): array {
        $headers = ['Content-Type' => 'application/json'];

        foreach ($endpoint->headers as $name => $value) {
            $headers[$name] = $this->renderTemplate((string) $value, $event, $payload);
        }

        if ($endpoint->signer !== null) {
            /** @var SignatureSigner $signer */
            $signer = app($endpoint->signer);

            foreach ($signer->sign($rawBody, $endpoint, $event) as $name => $value) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    protected function renderTemplate(string $template, ?FanoutEvent $event, array $payload): string
    {
        $tokens = [
            '{event.id}'          => $event?->getKey() ?? '',
            '{event.type}'        => $event?->event_type ?? ($payload['type'] ?? ''),
            '{event.received_at}' => $event?->received_at?->toIso8601String() ?? '',
            '{event.profile}'     => $event?->profile ?? '',
        ];

        return strtr($template, $tokens);
    }

    protected function encodePayload(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function truncate(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $limit = (int) config('fanout.pruning.response_body_byte_limit', 16_384);

        return strlen($body) > $limit ? substr($body, 0, $limit) : $body;
    }

    protected function failOrRetry(
        FanoutDelivery $delivery,
        EndpointConfig $endpoint,
        ?int $statusCode,
        ?string $body,
        ?Throwable $error,
    ): void {
        $delivery->refresh();
        $attempts = (int) $delivery->attempts;
        $max      = $endpoint->maxAttempts();

        $errorMessage = $error?->getMessage();

        if ($attempts >= $max) {
            $delivery->forceFill([
                'status'             => FanoutDelivery::STATUS_FAILED,
                'last_status_code'   => $statusCode,
                'last_response_body' => $body,
                'last_error'         => $errorMessage,
                'completed_at'       => now(),
                'next_attempt_at'    => null,
                'purgeable_at'       => now()->addDays((int) config('fanout.pruning.keep_failed_events_days', 90)),
            ])->save();

            Log::channel(config('fanout.log_channel', 'stack'))->warning('Fanout delivery failed', [
                'delivery_id'  => $delivery->getKey(),
                'event_id'     => $delivery->event_id,
                'endpoint'     => $endpoint->name,
                'profile'      => $endpoint->profileName,
                'attempts'     => $attempts,
                'status_code'  => $statusCode,
                'error'        => $errorMessage,
            ]);

            return;
        }

        $delay = $endpoint->backoffSeconds($attempts);

        $this->reschedule($delivery, $endpoint, $delay, statusCode: $statusCode, body: $body, error: $errorMessage);
    }

    protected function reschedule(
        FanoutDelivery $delivery,
        EndpointConfig $endpoint,
        int $delaySeconds,
        ?int $statusCode = null,
        ?string $body = null,
        ?string $error = null,
    ): void {
        // Reschedule never increments attempts — increments happen once,
        // immediately before the HTTP send. Throttling skips the send entirely
        // and exits before the increment runs.
        $update = [
            'status'          => FanoutDelivery::STATUS_PENDING,
            'next_attempt_at' => now()->addSeconds($delaySeconds),
        ];

        if ($statusCode !== null) {
            $update['last_status_code'] = $statusCode;
        }

        if ($body !== null) {
            $update['last_response_body'] = $body;
        }

        if ($error !== null) {
            $update['last_error'] = $error;
        }

        $delivery->forceFill($update)->save();

        static::dispatchForDelivery($delivery->getKey(), $this->payload)
            ->delay($delaySeconds);
    }
}
