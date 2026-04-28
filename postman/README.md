# Fanout smoke tests (Postman / Newman)

Two collections in this directory, covering the same package from two angles:

| File | What it does | When to use |
|---|---|---|
| `Fanout-Smoke-Tests.postman_collection.json` | Hits a deployed receiver, then polls **webhook.site** to verify deliveries arrived. Includes signed-flow tests (HMAC computed in the pre-request script + verified on the way out). | Quick to set up, no extra config. Confirms real network egress works. |
| `Fanout-Sink-Tests.postman_collection.json`  | Hits a deployed receiver, then polls the package's **built-in test sink** (`fanout/_sink/{name}`) to verify deliveries arrived. | Self-contained, deterministic, no internet, ~5x faster. Best for repeatable CI runs. |

## Setup

### Common (both collections)

In `config/fanout.php`, define the profiles you want to test (see `config/fanout.php` in this repo for ready-to-paste `smoke-test`, `smoke-test-signed`, and `smoke-sink` blocks). Run `php artisan migrate`. If your queue is async, also start a worker: `php artisan queue:work --queue=fanout`.

### `Fanout-Sink-Tests.postman_collection.json` only

Enable the sink in `.env`:

```
FANOUT_TEST_SINK_ENABLED=true
```

Run `php artisan migrate` once more to create the `fanout_test_captures` table (added in the package's third migration).

Verify the sink routes are mounted:

```bash
php artisan route:list | grep _sink
```

You should see `POST /fanout/_sink/{name}`, `GET /fanout/_sink/{name}/captures`, `DELETE /fanout/_sink/{name}`.

**Never enable the sink in production.** Captures are stored unencrypted by design (debug data) and the routes are unauthenticated.

### `Fanout-Smoke-Tests.postman_collection.json` only

Set the signed-flow secret in `.env` so it matches what the collection signs with:

```
FANOUT_SMOKE_SECRET=smoke-test-secret-change-me
```

## Running

### From the Postman UI

Import the JSON, set the collection variables (`base_url`, `webhook_token`, `fanout_secret` as applicable), then **Run collection**.

### From the command line (Newman)

```bash
# Pattern A — webhook.site
npx newman run packages/fanout/postman/Fanout-Smoke-Tests.postman_collection.json \
    --env-var base_url=https://crumbls.test \
    --env-var webhook_token=YOUR-WEBHOOK-SITE-TOKEN \
    --env-var fanout_secret=smoke-test-secret-change-me \
    --insecure

# Pattern B — built-in sink
npx newman run packages/fanout/postman/Fanout-Sink-Tests.postman_collection.json \
    --env-var base_url=https://crumbls.test \
    --insecure
```

The `--insecure` flag skips TLS verification so it works against `*.test` Valet/Herd hosts. Drop it for production hosts.

## What each suite asserts

### Pattern A — webhook.site

| # | What it does | Why it matters |
|---|---|---|
| 1 | POSTs to a non-existent profile | Confirms the receiver route is mounted and 404s correctly. |
| 2 | POSTs an unsigned event, captures `event_id` | Confirms a 202 response, persisted event row, queued dispatch. |
| 3 | Polls webhook.site's API for the captured event id | Confirms the destination *actually received* the request, with `X-Fanout-Source` / `X-Fanout-Profile` / `X-Fanout-Event` header templating intact and the body round-tripped untouched. |
| 4 | POSTs a signed event with a valid HMAC | Confirms inbound signature verification accepts the request. |
| 5 | POSTs the signed profile with `X-Signature: deadbeef` | Confirms invalid signatures get a 401 and never reach the queue. |
| 6 | Polls webhook.site for the signed delivery | Confirms the outbound destination received the request, including a fresh `X-Fanout-Signature: sha256=…` header from `HmacSha256Signer` that verifies against the same secret. |

### Pattern B — built-in sink

| # | What it does | Why it matters |
|---|---|---|
| 1 | DELETE `/fanout/_sink/staging` (and `dev`) | Resets state so subsequent assertions are deterministic. |
| 2 | POSTs an event through `smoke-sink` profile | Confirms 202, persisted event, queued dispatch to two endpoints. |
| 3 | GETs `/fanout/_sink/staging/captures` | Confirms staging endpoint received it with correct templated headers and a body that round-tripped through encryption + dispatch + delivery. |
| 4 | GETs `/fanout/_sink/dev/captures` | Confirms the same event reached dev too — proves real fan-out, not just one-to-one forwarding. |
| 5 | DELETEs sink, fires three events, asserts `count = 3` | Confirms multi-event behavior and the DELETE endpoint's clearing semantics. |

## Caveats

- The signed-flow secret in Pattern A is the same on both sides only because this is a smoke test — production should use distinct secrets per side.
- webhook.site rate-limits and rotates tokens; for ongoing CI use, prefer Pattern B (no third-party dependency at all).
- If `QUEUE_CONNECTION` is async, deliveries may not have landed by the time the polling steps run. Either set `QUEUE_CONNECTION=sync` for the test run, or add `php artisan queue:work --once --stop-when-empty --queue=fanout` between firing and asserting.
- Pattern B only works while `FANOUT_TEST_SINK_ENABLED=true`. If the sink isn't responding, double-check that flag and `php artisan config:clear`.
