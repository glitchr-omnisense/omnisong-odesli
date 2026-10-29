# Changelog

## [Unreleased]

### Fixed

- An unreachable Odesli no longer carries the key: the `UnavailableException` quoted the HTTP
  client's message, the whole URL with `key=`, and wrapped the client's own exception, and every trace
  kept the request's query in its arguments. The message and the previous - now a copy of the
  client's exception, the same class and code - have it masked (`key=***`), and no trace of the
  chain keeps its arguments. Needs omnisense/omnisong's `Http\Redaction`.
- `Retry-After` written as an HTTP date gives the seconds until then (at least 1), where it gave 0.
- composer.json's description no longer claims an ISRC or a UPC, which the package does not read.
