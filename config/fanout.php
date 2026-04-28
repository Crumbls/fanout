<?php

declare(strict_types=1);

use Crumbls\Fanout\Models\FanoutDelivery;
use Crumbls\Fanout\Models\FanoutEvent;
use Crumbls\Fanout\Signers\HmacSha256Signer;
use Crumbls\Fanout\Validators\HmacSha256SignatureValidator;

return [

    /*
    |--------------------------------------------------------------------------
    | Receiver routes
    |--------------------------------------------------------------------------
    |
    | The package mounts a single POST endpoint per profile under this prefix.
    | A request to POST {prefix}/{profile} is matched against config below.
    |
    */
    'route' => [
        'enabled'    => true,
        'prefix'     => env('FANOUT_ROUTE_PREFIX', 'fanout/in'),
        'middleware' => ['api'],
        'domain'     => env('FANOUT_ROUTE_DOMAIN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('FANOUT_QUEUE_CONNECTION'),
        'queue'      => env('FANOUT_QUEUE', 'fanout'),
    ],

    /*
    |--------------------------------------------------------------------------
    | In-package webhook test sink
    |--------------------------------------------------------------------------
    |
    | An optional self-hosted webhook catcher useful for end-to-end smoke
    | tests against your own deployment. Captures land in the
    | `fanout_test_captures` table for later inspection / assertion.
    |
    | NEVER enable this in production. Captures are stored unencrypted by
    | design (debug data) and the routes have no authentication. The flag
    | defaults off precisely because of that.
    |
    | Routes when enabled:
    |   POST   {sink_prefix}/{name}             — capture a request
    |   GET    {sink_prefix}/{name}/captures    — list recent captures
    |   DELETE {sink_prefix}/{name}             — clear captures for that sink
    |
    */
    'testing' => [
        'sink_enabled'    => env('FANOUT_TEST_SINK_ENABLED', false),
        'sink_prefix'     => env('FANOUT_TEST_SINK_PREFIX', 'fanout/_sink'),
        'sink_middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pruning policy
    |--------------------------------------------------------------------------
    */
    'pruning' => [
        'enabled'                  => true,
        'keep_events_days'         => 30,
        'keep_failed_events_days'  => 90,
        'response_body_byte_limit' => 16_384,
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Replace these with subclasses to add envelope encryption, per-tenant keys,
    | additional relationships, scopes, etc. The package always resolves the
    | model class through the container, never via direct ::class reference.
    |
    */
    'models' => [
        'event'    => FanoutEvent::class,
        'delivery' => FanoutDelivery::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Profiles
    |--------------------------------------------------------------------------
    |
    | Each profile = one inbound webhook source. Profiles are matched by URL
    | segment: POST /{route.prefix}/{profile_key}.
    |
    | persist:
    |   'full'     -> event + payload + delivery rows (default; replayable)
    |   'metadata' -> event row, payload column null (audit trail without body)
    |   'none'     -> no rows; deliveries dispatched as ephemeral jobs
    |
    | validator: optional. Class implementing SignatureValidator. If omitted,
    |   the receiver accepts any caller. Only do this for trusted internal
    |   sources.
    |
    | Per-endpoint transform / filter accept a class string. Closures cannot
    | live in cached config — register them at runtime via the Fanout manager.
    |
    */
    'profiles' => [

        // Example. Remove or adjust to taste — and add additional profiles.
        // 'stripe-prod' => [
        //     'persist'                       => 'full',
        //     'validator'                     => HmacSha256SignatureValidator::class,
        //     'secret'                        => env('STRIPE_WEBHOOK_SECRET'),
        //     'signature_header'              => 'X-Signature',
        //     'continue_on_endpoint_failure'  => true,
        //
        //     'endpoints' => [
        //
        //         'staging' => [
        //             'url'         => env('STAGING_WEBHOOK_URL'),
        //             'enabled'     => true,
        //             'environment' => 'staging',
        //             'timeout'     => 10,
        //             'headers'     => [
        //                 'X-Fanout-Source' => 'production',
        //                 'X-Fanout-Event'  => '{event.id}',
        //             ],
        //             'signer'      => HmacSha256Signer::class,
        //             'secret'      => env('STAGING_WEBHOOK_SECRET'),
        //             'retry'       => ['attempts' => 5, 'backoff' => 'exponential', 'base_seconds' => 5],
        //             'rate_limit'  => ['per_minute' => 60, 'burst' => 10],
        //             'transform'   => null,
        //             'filter'      => null,
        //         ],
        //
        //         'dev' => [
        //             'url'         => env('DEV_WEBHOOK_URL'),
        //             'enabled'     => env('FANOUT_DEV_ENABLED', false),
        //             'environment' => 'dev',
        //             'timeout'     => 10,
        //             'retry'       => ['attempts' => 3, 'backoff' => 'exponential', 'base_seconds' => 10],
        //         ],
        //     ],
        // ],

    ],

];
