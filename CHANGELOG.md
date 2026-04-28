# Changelog

All notable changes to `crumbls/fanout` will be documented in this file.

## [Unreleased]

### Added
- Initial release.
- HTTP receiver mounted at `POST /fanout/in/{profile}` with optional signature verification.
- Three-mode persistence per profile: `full`, `metadata`, `none`.
- Per-endpoint retry with fixed / linear / exponential backoff.
- Per-endpoint rate limiting via Laravel's `RateLimiter`.
- Pluggable `SignatureValidator`, `SignatureSigner`, `PayloadTransformer`, `PayloadFilter` contracts.
- Built-in validators: `HmacSha256SignatureValidator`, `StripeSignatureValidator`, `GithubSignatureValidator`, `SpatieSignatureValidator`.
- Built-in signers: `HmacSha256Signer`, `PassthroughSigner`.
- Header templating with `{event.id}`, `{event.type}`, `{event.profile}`, `{event.received_at}` tokens.
- Replay system: `php artisan fanout:replay {event}`, `fanout:replay-failed`, programmatic `Fanout::replay()` and `Fanout::replayFailed()`.
- Pruning command (`fanout:purge`) using pre-computed `purgeable_at` columns.
- Event payload, headers, response body all encrypted at rest by default.
- Eloquent models swappable via `config('fanout.models.*')` for custom encryption strategies.
