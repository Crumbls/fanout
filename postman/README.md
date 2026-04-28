# Fanout smoke tests (Postman / Newman)

End-to-end tests that fire real HTTP at a deployed instance of `crumbls/fanout`, then verify the destination (webhook.site) actually received the request.

## Setup (one-time)

In the host Laravel app's `config/fanout.php`, define two profiles:

- `smoke-test` — no validator, fan-out target = your webhook.site URL
- `smoke-test-signed` — `HmacSha256SignatureValidator`, secret from `FANOUT_SMOKE_SECRET`, fan-out target = same webhook.site URL with `HmacSha256Signer` re-signing

(See `config/fanout.php` in this repo for an example block ready to paste.)

Optional `.env` entries:

```
FANOUT_SMOKE_SECRET=smoke-test-secret-change-me
```

`php artisan migrate` (one-off) and `php artisan queue:work --queue=fanout` (skip if `QUEUE_CONNECTION=sync`).

## Run from the Postman UI

Import `Fanout-Smoke-Tests.postman_collection.json`, set the collection variables to match your environment:

- `base_url` — where the receiver is mounted (default `https://crumbls.test`)
- `webhook_token` — the GUID from your webhook.site URL
- `fanout_secret` — must match `FANOUT_SMOKE_SECRET` on the host

Then **Run collection**.

## Run from the command line (Newman)

```bash
npx newman run packages/fanout/postman/Fanout-Smoke-Tests.postman_collection.json \
    --env-var base_url=https://crumbls.test \
    --env-var webhook_token=0dd86b32-0d93-4387-bb95-eb34a028fa7c \
    --env-var fanout_secret=smoke-test-secret-change-me
```

## What the suite asserts

| # | What it does | Why it matters |
|---|---|---|
| 1 | POSTs to a non-existent profile | Confirms the receiver route is mounted and 404s correctly. |
| 2 | POSTs an unsigned event, captures `event_id` | Confirms a 202 response, persisted event row, queued dispatch. |
| 3 | Polls webhook.site's API for the captured event id | Confirms the destination *actually received* the request, with `X-Fanout-Source` / `X-Fanout-Profile` / `X-Fanout-Event` header templating intact and the body round-tripped untouched. |
| 4 | POSTs a signed event with a valid HMAC | Confirms inbound signature verification accepts the request. |
| 5 | POSTs the signed profile with `X-Signature: deadbeef` | Confirms invalid signatures get a 401 and never reach the queue. |
| 6 | Polls webhook.site for the signed delivery | Confirms the outbound destination received the request, including a fresh `X-Fanout-Signature: sha256=…` header from `HmacSha256Signer` that verifies against the same secret. |

## Caveats

- The signed-flow secret is the same on both sides only because this is a smoke test — production should use distinct secrets per side.
- webhook.site rate-limits and rotates tokens; for ongoing CI use, generate a fresh token periodically or stand up your own catcher (see "Pattern B — built-in test sink" in the package README).
- If `QUEUE_CONNECTION` is async, deliveries may not have landed by the time tests #3 / #6 poll. Either set `QUEUE_CONNECTION=sync` for the test run, or run `php artisan queue:work --once --stop-when-empty --queue=fanout` between firing and asserting.
