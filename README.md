# Laravel Axiom Log

Batched log handler for [Axiom](https://axiom.co) in Laravel. Buffers log records in memory and flushes them as a single POST to Axiom's ingest API at end of request (or when batch size threshold is reached).

> **Looking for request logging?** The request-logging middleware (now `RequestSensor`) moved to [devtime-ltd/laravel-observability-log](https://github.com/devtime-ltd/laravel-observability-log) as of v0.4.0. It is provider-agnostic and works alongside this handler, Better Stack, stderr, or any other Laravel log channel.

## Installation

```bash
composer require devtime-ltd/laravel-axiom-log
```

## Configuration

Add a log channel to your `config/logging.php`:

```php
'axiom' => [
    'driver' => 'monolog',
    'level' => env('LOG_LEVEL', 'debug'),
    'handler' => \DevtimeLtd\LaravelAxiomLog\AxiomHandler::class,
    'handler_with' => [
        'apiToken' => env('AXIOM_LOG_TOKEN'),
        'dataset'  => env('AXIOM_LOG_DATASET'),
    ],
    'processors' => [PsrLogMessageProcessor::class],
],
```

`host` (default: `https://api.axiom.co`), `batchSize` (default: `50`), `timeout` (default: `5` seconds, applied to ingest cURL calls) and `shutdownTimeout` (default: `2` seconds, applied when flushing during PHP shutdown so an Axiom outage cannot stall the request) can be added to `handler_with` if you need non-default values.

Then add `axiom` to your `LOG_STACK`, or use it directly:

```php
Log::channel('axiom')->info('something happened', ['key' => 'value']);
```

Need multiple Axiom channels with different datasets? Just define more entries:

```php
'axiom-requests' => [
    'driver' => 'monolog',
    'level' => env('LOG_LEVEL', 'debug'),
    'handler' => \DevtimeLtd\LaravelAxiomLog\AxiomHandler::class,
    'handler_with' => [
        'apiToken' => env('AXIOM_LOG_TOKEN'),
        'dataset'  => 'acme_requests_log',
    ],
    'processors' => [PsrLogMessageProcessor::class],
],
'axiom-activity' => [
    'driver' => 'monolog',
    'level' => env('LOG_LEVEL', 'debug'),
    'handler' => \DevtimeLtd\LaravelAxiomLog\AxiomHandler::class,
    'handler_with' => [
        'apiToken' => env('AXIOM_LOG_TOKEN'),
        'dataset'  => 'acme_activity_log',
    ],
    'processors' => [PsrLogMessageProcessor::class],
],
```

## When records are sent

Records are buffered and flushed in any of the following situations:

- The buffer reaches `batchSize` (default 50).
- The handler is destructed at end of a synchronous request (PHP shutdown).
- A queue worker finishes a job (`JobProcessed`, `JobExceptionOccurred`) or stops (`WorkerStopping`).
- An Octane request, task, or tick terminates (auto-detected if `laravel/octane` is installed).

The queue and Octane hooks are registered automatically by `LaravelAxiomLogServiceProvider` (auto-discovered — no manual setup needed). If you have another long-lived process boundary (custom long-running command, scheduled job, etc.) where you want to flush eagerly, call `$handler->flush()` directly or hook into your own event.

## Testing

```bash
composer test
```

## License

MIT
