# Changelog

## [Unreleased]

### Fixed

- `Retry-After` written as an HTTP date gives the seconds until then (at least 1), where it gave 0.
- composer.json's description no longer claims an ISRC or a UPC, which the package does not read.
