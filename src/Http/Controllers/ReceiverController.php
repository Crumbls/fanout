<?php

declare(strict_types=1);

namespace Crumbls\Fanout\Http\Controllers;

use Crumbls\Fanout\Contracts\SignatureValidator;
use Crumbls\Fanout\Fanout;
use Crumbls\Fanout\Jobs\DeliverFanoutEventJob;
use Crumbls\Fanout\Jobs\DispatchFanoutEventJob;
use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Support\ProfileConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReceiverController
{
    public function __construct(protected Fanout $fanout) {}

    public function __invoke(Request $request, string $profile): JsonResponse
    {
        if (! $this->fanout->hasProfile($profile)) {
            throw new HttpException(404, "Unknown fanout profile [{$profile}]");
        }

        $config  = $this->fanout->profile($profile);
        $rawBody = $request->getContent();
        $headers = $request->headers->all();

        if (! $this->verifySignature($config, $rawBody, $headers)) {
            throw new HttpException(401, 'Invalid signature');
        }

        $payload = $this->decodePayload($rawBody);

        return match ($config->persist) {
            'full', 'metadata' => $this->handlePersisted($config, $payload, $headers, $rawBody),
            'none'             => $this->handleEphemeral($config, $payload, $headers),
        };
    }

    protected function verifySignature(ProfileConfig $config, string $rawBody, array $headers): bool
    {
        if ($config->validator === null) {
            return true;
        }

        /** @var SignatureValidator $validator */
        $validator = app($config->validator);

        return $validator->verify($rawBody, $headers, [
            'secret'           => $config->secret,
            'signature_header' => $config->signatureHeader,
        ]);
    }

    protected function handlePersisted(
        ProfileConfig $config,
        array $payload,
        array $headers,
        string $rawBody,
    ): JsonResponse {
        $storePayload = $config->shouldStorePayload();

        $event = $this->fanout->newEvent();
        $event->forceFill([
            'profile'        => $config->name,
            'event_type'     => $payload['type'] ?? null,
            'schema_version' => $payload['schema_version'] ?? null,
            'is_test'        => isset($payload['livemode']) ? ! (bool) $payload['livemode'] : false,
            'headers'        => $storePayload ? $headers : $this->safeHeaderList($headers),
            'payload'        => $storePayload ? $payload : null,
            'signature'      => $this->originalSignatureHeader($config, $headers),
            'received_at'    => now(),
            'purgeable_at'   => now()->addDays((int) config('fanout.pruning.keep_events_days', 30)),
        ])->save();

        // In metadata mode the payload isn't stored in the DB, so we carry
        // it through the dispatch job so deliveries can still send it.
        $payloadForJob = $storePayload ? null : $payload;

        DispatchFanoutEventJob::dispatch($event->getKey(), $payloadForJob)
            ->onConnection(config('fanout.queue.connection'))
            ->onQueue(config('fanout.queue.queue'));

        return response()->json([
            'accepted' => true,
            'event_id' => $event->getKey(),
            'persist'  => $config->persist,
        ], 202);
    }

    protected function handleEphemeral(ProfileConfig $config, array $payload, array $headers): JsonResponse
    {
        foreach ($config->enabledEndpoints() as $endpoint) {
            DeliverFanoutEventJob::dispatchEphemeral(
                profileName: $config->name,
                endpointName: $endpoint->name,
                payload: $payload,
                originalHeaders: $headers,
            );
        }

        return response()->json([
            'accepted' => true,
            'persist'  => 'none',
        ], 202);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodePayload(string $rawBody): array
    {
        if ($rawBody === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : ['_raw' => $rawBody];
    }

    /**
     * In metadata mode we still want to keep some headers for observability,
     * but not headers that frequently carry tokens or PII.
     *
     * @param  array<string, array<int, string>>  $headers
     * @return array<string, array<int, string>>
     */
    protected function safeHeaderList(array $headers): array
    {
        $allow = [
            'content-type',
            'user-agent',
            'x-request-id',
            'x-correlation-id',
            'x-event-type',
        ];

        return array_intersect_key(
            array_change_key_case($headers, CASE_LOWER),
            array_flip($allow),
        );
    }

    protected function originalSignatureHeader(ProfileConfig $config, array $headers): ?string
    {
        $header = strtolower($config->signatureHeader ?? '');

        if ($header === '') {
            return null;
        }

        foreach ($headers as $key => $values) {
            if (strtolower((string) $key) === $header) {
                return is_array($values) ? ($values[0] ?? null) : (string) $values;
            }
        }

        return null;
    }
}
