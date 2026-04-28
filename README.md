# crumbls/fanout

Catch incoming webhooks and fan them out to multiple downstream destinations — staging, dev, secondary services — with retries, signing, transformation, filtering, rate limiting, and replay.

Solves the "production webhooks never reach staging/dev" problem and the broader "I need to mirror webhooks across environments without writing a custom forwarder per source" problem.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- A queue driver (Redis, SQS, database — anything Laravel supports)

## Install

```bash
composer require crumbls/fanout
php artisan fanout:install
php artisan migrate
```

`fanout:install` publishes `config/fanout.php` and the two migrations into your app.

## Concepts

- **Profile** — one inbound webhook source. Identified by URL segment: `POST /{prefix}/{profile}`.
- **Endpoint** — one outbound destination configured under a profile. Each endpoint has its own URL, headers, signing, retries, rate limit, transform, and filter.
- **Event** — one inbound HTTP request that matched a profile (`fanout_events` row).
- **Delivery** — one attempt to push an event to one endpoint (`fanout_deliveries` row).
- **Persist mode** — per-profile choice between `full`, `metadata`, and `none`. See below.

## Configuration

```php
// config/fanout.php
return [
    'route' => [
        'enabled'    => true,
        'prefix'     => env('FANOUT_ROUTE_PREFIX', 'fanout/in'),
        'middleware' => ['api'],
    ],

    'queue' => [
        'connection' => env('FANOUT_QUEUE_CONNECTION'),
        'queue'      => env('FANOUT_QUEUE', 'fanout'),
    ],

    'models' => [
        'event'    => Crumbls\Fanout\Models\FanoutEvent::class,
        'delivery' => Crumbls\Fanout\Models\FanoutDelivery::class,
    ],

    'profiles' => [

        'stripe-prod' => [
            'persist'                       => 'full',
            'validator'                     => Crumbls\Fanout\Validators\StripeSignatureValidator::class,
            'secret'                        => env('STRIPE_WEBHOOK_SECRET'),
            'signature_header'              => 'Stripe-Signature',
            'continue_on_endpoint_failure'  => true,

            'endpoints' => [
                'staging' => [
                    'url'         => env('STAGING_WEBHOOK_URL'),
                    'enabled'     => true,
                    'environment' => 'staging',
                    'timeout'     => 10,
                    'headers'     => [
                        'X-Fanout-Source' => 'production',
                        'X-Fanout-Event'  => '{event.id}',
                    ],
                    'signer'      => Crumbls\Fanout\Signers\HmacSha256Signer::class,
                    'secret'      => env('STAGING_WEBHOOK_SECRET'),
                    'signature_header' => 'X-Fanout-Signature',
                    'retry'       => ['attempts' => 5, 'backoff' => 'exponential', 'base_seconds' => 5],
                    'rate_limit'  => ['per_minute' => 60],
                ],

                'dev' => [
                    'url'         => env('DEV_WEBHOOK_URL'),
                    'enabled'     => env('FANOUT_DEV_ENABLED', false),
                    'environment' => 'dev',
                ],
            ],
        ],

    ],
];
```

Stripe sends webhooks to `https://your-app.test/fanout/in/stripe-prod`. The receiver verifies the signature, persists the event, and dispatches one async delivery job per enabled endpoint.

## Persist modes

Per-profile setting that controls how much of an inbound event is stored.

| Mode | Event row | Payload column | Delivery rows | Replayable | Use when |
|---|---|---|---|---|---|
| `full` (default) | yes | encrypted, full body | yes | yes | You want full audit + replay |
| `metadata` | yes | null | yes | no | You need a timeline / response codes but the body is too sensitive to keep |
| `none` | no | n/a | no | no | Pure forwarder — no DB writes |

In `none` mode the receiver dispatches ephemeral delivery jobs that carry the payload in the job constructor; failures land in Laravel's `failed_jobs` table.

## Encryption at rest

`payload`, `headers`, `request_payload`, `request_headers`, and `last_response_body` are all cast as Laravel `encrypted` / `encrypted:array`. Encryption key is your app `APP_KEY`.

If you need a different strategy — envelope encryption, per-tenant keys, KMS — extend the model and override the casts:

```php
namespace App\Models;

use Crumbls\Fanout\Models\FanoutEvent as BaseEvent;

class FanoutEvent extends BaseEvent
{
    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'payload' => MyKmsEncryptedArrayCast::class,
            'headers' => MyKmsEncryptedArrayCast::class,
        ]);
    }
}
```

Then point the package at it:

```php
// config/fanout.php
'models' => [
    'event'    => App\Models\FanoutEvent::class,
    'delivery' => Crumbls\Fanout\Models\FanoutDelivery::class,
],
```

## Validators (inbound)

Optional. If unconfigured, the receiver accepts any caller — only do that for trusted internal sources.

Built-in:
- `HmacSha256SignatureValidator` — generic. Configurable `signature_header` and optional `signature_prefix` (e.g. `sha256=`).
- `StripeSignatureValidator` — Stripe `t=<unix>,v1=<hash>` scheme with timestamp tolerance.
- `GithubSignatureValidator` — `X-Hub-Signature-256: sha256=<hash>`.
- `SpatieSignatureValidator` — compatible with `spatie/laravel-webhook-client`'s default `Signature` header.

Bring your own by implementing `Crumbls\Fanout\Contracts\SignatureValidator`.

## Signers (outbound)

Per endpoint. Built-in:
- `HmacSha256Signer` — re-signs with the endpoint's own `secret`.
- `PassthroughSigner` — forwards the original signature header (only useful when destination shares the source secret AND you don't transform the payload).

Implement `Crumbls\Fanout\Contracts\SignatureSigner` for custom schemes.

## Filters & transformers

Per endpoint, accepting class strings (closures can't live in cached config — register them at runtime via the manager if you need that).

```php
class DropTestEvents implements Crumbls\Fanout\Contracts\PayloadFilter
{
    public function shouldDeliver(array $payload, $endpoint, $event): bool
    {
        return ! ($payload['livemode'] ?? true);
    }
}

class StripPii implements Crumbls\Fanout\Contracts\PayloadTransformer
{
    public function transform(array $payload, $endpoint, $event): array
    {
        unset($payload['data']['object']['email']);
        return $payload;
    }
}

// then in config:
'filter'    => DropTestEvents::class,
'transform' => StripPii::class,
```

## Header templating

Endpoint headers support these tokens:

- `{event.id}`
- `{event.type}`
- `{event.profile}`
- `{event.received_at}`

```php
'headers' => [
    'X-Fanout-Source' => 'production',
    'X-Fanout-Event'  => '{event.id}',
],
```

## Retries

Per endpoint:

```php
'retry' => [
    'attempts'     => 5,
    'backoff'      => 'exponential', // 'fixed' | 'linear' | 'exponential'
    'base_seconds' => 5,
],
```

Each attempt is its own queue job. Failed deliveries stay in `fanout_deliveries` with `status = failed` so they're easy to find and replay.

## Rate limiting

Per endpoint:

```php
'rate_limit' => ['per_minute' => 60],
```

When exceeded, the delivery is rescheduled (without consuming an attempt).

## Replay

```bash
# Replay one event to all of its endpoints
php artisan fanout:replay 0193e4f7-...

# Replay just one endpoint
php artisan fanout:replay 0193e4f7-... --endpoint=staging

# Bulk replay every failed delivery (optionally scoped)
php artisan fanout:replay-failed --profile=stripe-prod --endpoint=dev
```

Programmatic equivalent:

```php
use Crumbls\Fanout\Facades\Fanout;

Fanout::replay($event);
Fanout::replay($eventId, endpoint: 'staging');
Fanout::replayFailed(profile: 'stripe-prod');
```

## Programmatic dispatch

Inject events into the pipeline as if a webhook had arrived (no signature check):

```php
Fanout::dispatch('stripe-prod', $payload, $headers);
```

## Pruning

```bash
php artisan fanout:purge          # removes rows past their purgeable_at
php artisan fanout:purge --dry-run
```

Schedule it in `routes/console.php`:

```php
Schedule::command('fanout:purge')->daily();
```

Retention windows (`pruning.keep_events_days`, `pruning.keep_failed_events_days`) are baked onto each row's `purgeable_at` at write time, so pruning is a single indexed range delete.

## Worker

Run a dedicated worker on the fanout queue:

```bash
php artisan queue:work --queue=fanout
```

Horizon is supported out of the box — events show up tagged with their job class.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT
