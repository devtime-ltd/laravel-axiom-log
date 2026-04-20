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

`host` (default: `https://api.axiom.co`) and `batchSize` (default: `50`) can be added to `handler_with` if you need non-default values.

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

## Testing

```bash
composer test
```

## License

MIT
