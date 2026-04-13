# Changelog

## [0.3.0] - 2026-04-13

### Breaking Changes

- Default request log message changed from `"request"` to `"http.request"` - update any Axiom queries filtering on the old value.

### Added

- Configurable log message via `message` config key / `LOG_REQUESTS_MESSAGE` env var (default: `http.request`).
- Configurable log level via `level` config key / `LOG_REQUESTS_LEVEL` env var (default: `info`).
- `LogRequest::message()` method to set the message at runtime - accepts a string, a closure `(Request, ?Response) -> string`, or null to revert to the config default.
- Logging failures are now reported to the default log channel via `Log::error()` instead of being silently swallowed.

### Fixed

- Added `level` option to channel config examples in README.

## [0.2.1] - 2026-04-13

### Fixed

- Added `PsrLogMessageProcessor` to channel config examples in README.

## [0.2.0] - 2026-04-13

### Breaking Changes

- Removed auto-registered `axiom` log channel. Users must now define their own channel in `config/logging.php`.

## [0.1.1] - 2026-04-11

### Fixed

- Added `.gitattributes` to exclude dev files from Composer installs.
- Added example screenshot to request logging section in README.

## [0.1.0] - 2026-04-10

Initial release.

- Batched Axiom log handler for Laravel (buffers records, flushes as single POST).
- Optional `LogRequest` middleware for structured request logging.
- `LogRequest::using()` and `LogRequest::extend()` callbacks for customising log entries.
- IP obfuscation via `ObfuscateIp` helper.
- Database query tracking with configurable slow query threshold.

[0.3.0]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/devtime-ltd/laravel-axiom-log/releases/tag/v0.1.0
