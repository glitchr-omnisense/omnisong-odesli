# Changelog

## [Unreleased]

### Added

- Its usage policy as data, `policy.json` (`extra.omnisense.policy`, `usagePolicy()`), verified 2026-11-10: nothing readable that day, so limits and rights unknown, a key required (the keyless access answers 401). The factory gives the catalogue its wait (omnisense/contracts' `Backoff`): a 429's `Retry-After`, a 503's, or a `RateLimit` field with nothing left holds the next calls, a `RateLimitedException` with nothing sent; `sentRequests()` counts the requests sent, so the registry's guard counts no answer held in memory. Requires `omnisense/contracts`. PHPStan at level max, the existing errors in `phpstan-baseline.neon`.

### Fixed

- An unreachable Odesli no longer carries the key: the `UnavailableException` quoted the HTTP
  client's message, the whole URL with `key=`, and wrapped the client's own exception, and every trace
  kept the request's query in its arguments. The message and the previous - now a copy of the
  client's exception, the same class and code - have it masked (`key=***`), and no trace of the
  chain keeps its arguments. Needs omnisense/omnisong's `Http\Redaction`.
- `Retry-After` written as an HTTP date gives the seconds until then (at least 1), where it gave 0.
- composer.json's description no longer claims an ISRC or a UPC, which the package does not read.
- An answer whose `entityUniqueId` is a list makes the release a `ProviderException`, where reading the entity by it threw a `TypeError`.
