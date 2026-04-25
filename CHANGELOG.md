# Changelog

## [0.6.0] - 2026-04-25

### Fixed

- Buffered records are now flushed when the handler is destructed, not only at batch size or on explicit `close()`. Previously, low-volume request scopes (e.g. a web/Inertia route producing a single `http.request` entry) could silently drop the buffer at PHP shutdown because Laravel never calls `close()` on Monolog handlers and `Monolog\Logger` has no `__destruct`.

### Added

- `LaravelAxiomLogServiceProvider` (auto-discovered) flushes any `AxiomHandler` buffers on `JobProcessed`, `JobExceptionOccurred`, and `WorkerStopping` events, so long-running queue workers no longer retain low-volume records across jobs until the batch threshold is hit.
- The same provider also flushes on Octane's `RequestTerminated`, `TaskTerminated`, and `TickTerminated` events when `laravel/octane` is installed (auto-detected).
- `AxiomHandler::flush()` is now public so it can be invoked directly (e.g. from custom listeners for Octane, scheduled tasks, or other long-lived process boundaries).

## [0.5.0] - 2026-04-21

### Fixed

- Throwables passed in log context (e.g. `Log::error('failed', ['exception' => $e])`) are now normalized to a structured array (`class`, `message`, `code`, `file`, `trace`, `previous`) before ingest. Previously they arrived in Axiom as `context.exception = {}` because PHP's `json_encode` drops non-public properties on `Exception`.
- Other non-encodable values in context/extra (resources, `DateTimeInterface`, `JsonSerializable`, `UnitEnum`) are now normalized instead of crashing the batch, matching the behaviour of other standard Monolog handlers.

### Changed

- `AxiomHandler` now composes `Monolog\Formatter\NormalizerFormatter` to normalize `context` and `extra` values before JSON encoding.

## [0.4.0] - 2026-04-18

### Breaking Changes

- Extracted the `LogRequest` middleware, `ObfuscateIp` helper, and `config/log-request.php` into a new package: [devtime-ltd/laravel-observability-log](https://github.com/devtime-ltd/laravel-observability-log). The middleware is provider-agnostic, so it no longer belongs here.
- Removed `AxiomLogServiceProvider` (there is no longer any config to publish from this package).
- Dropped `illuminate/database` requirement (only the middleware needed it).

### Migration

1. `composer require devtime-ltd/laravel-observability-log`
2. Update imports from `DevtimeLtd\LaravelAxiomLog\LogRequest` / `ObfuscateIp` to ~~`DevtimeLtd\LaravelObservabilityLog\LogRequest`~~ `DevtimeLtd\LaravelObservabilityLog\RequestSensor` / `ObfuscateIp` (`LogRequest` was renamed to `RequestSensor` in observability-log v0.2.0).
3. Republish the config: `php artisan vendor:publish --tag=observability-log`. The new file lives at `config/observability-log.php` with settings nested under a `requests` section. Delete the old `config/log-request.php`.
4. ~~Existing `LOG_REQUESTS_*` env vars continue to work unchanged.~~ (env vars were renamed to `OBSERVABILITY_LOG_*` in observability-log v0.2.0 — see that package's CHANGELOG for details.)

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

[0.6.0]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.2.1...v0.3.0
[0.2.1]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/devtime-ltd/laravel-axiom-log/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/devtime-ltd/laravel-axiom-log/releases/tag/v0.1.0
