<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use WeakReference;

/**
 * Monolog handler that batches log records and sends them to Axiom's
 * ingest API in a single HTTP request when the handler is closed
 * (end of request) or when the batch size threshold is reached.
 */
class AxiomHandler extends AbstractProcessingHandler
{
    const DEFAULT_HOST = 'https://api.axiom.co';

    const DEFAULT_BATCH_SIZE = 50;

    const DEFAULT_TIMEOUT = 5;

    const DEFAULT_SHUTDOWN_TIMEOUT = 2;

    /** @var array<int, WeakReference<self>> */
    private static array $instances = [];

    private static bool $fieldNameSanitizationWarned = false;

    private static bool $fieldNameCollisionWarned = false;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private readonly ExceptionContextNormalizer $normalizer;

    private bool $shuttingDown = false;

    public function __construct(
        private readonly string $apiToken,
        private readonly string $dataset,
        private readonly string $host = self::DEFAULT_HOST,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        Level|int|string $level = Level::Debug,
        bool $bubble = true,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $shutdownTimeout = self::DEFAULT_SHUTDOWN_TIMEOUT,
        private readonly bool $warnOnSanitization = true,
    ) {
        parent::__construct($level, $bubble);
        $this->normalizer = new ExceptionContextNormalizer;
        self::$instances[spl_object_id($this)] = WeakReference::create($this);
    }

    /**
     * Live AxiomHandler instances. Used by LaravelAxiomLogServiceProvider to
     * flush buffers at queue/Octane boundaries regardless of how Laravel or
     * Monolog may have wrapped the handlers (e.g. inside WhatFailureGroupHandler
     * for stack channels with `ignore_exceptions=true`).
     *
     * @return list<self>
     */
    public static function instances(): array
    {
        $alive = [];
        foreach (self::$instances as $id => $ref) {
            $instance = $ref->get();
            if ($instance === null) {
                unset(self::$instances[$id]);
                continue;
            }
            $alive[] = $instance;
        }

        return $alive;
    }

    protected function write(LogRecord $record): void
    {
        $this->buffer[] = $this->formatRecord($record);

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    public function __destruct()
    {
        unset(self::$instances[spl_object_id($this)]);
        $this->shuttingDown = true;
        $this->close();
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $payload = $this->buffer;
        $this->buffer = [];

        $url = rtrim($this->host, '/').'/v1/datasets/'.$this->dataset.'/ingest';
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            return;
        }

        $this->send($url, $json);
    }

    /**
     * @param  non-empty-string  $url
     * @param  non-empty-string  $json
     */
    protected function send(string $url, string $json): void
    {
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer '.$this->apiToken,
                    'Content-Type: application/json',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->shuttingDown ? $this->shutdownTimeout : $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);

            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // Logging should never crash the app
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatRecord(LogRecord $record): array
    {
        $event = [
            '_time' => $record->datetime->format('c'),
            'level' => $record->level->name,
            'message' => $record->message,
            'channel' => $record->channel,
        ];

        if (! empty($record->context)) {
            $event['context'] = $this->sanitizeKeys($this->normalizer->normalizeValue($record->context));
        }

        if (! empty($record->extra)) {
            $event['extra'] = $this->sanitizeKeys($this->normalizer->normalizeValue($record->extra));
        }

        return $event;
    }

    /**
     * Replace backslashes in field names with `__`. Axiom rejects field names
     * containing `\` (the only character it hard-rejects, confirmed by probing
     * the ingest API), and the handler's catch-all in `send()` would silently
     * drop the entire batch on rejection. The most common source is Monolog
     * wrapping non-`JsonSerializable` objects as `[ClassName => value]` where
     * `ClassName` is a namespaced FQCN.
     */
    private function sanitizeKeys(mixed $data): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && str_contains($key, '\\')) {
                if ($this->warnOnSanitization) {
                    self::warnFieldNameSanitizationOnce($key);
                }
                $key = str_replace('\\', '__', $key);
            }
            if (array_key_exists($key, $sanitized) && $this->warnOnSanitization) {
                self::warnFieldNameCollisionOnce((string) $key);
            }
            $sanitized[$key] = $this->sanitizeKeys($value);
        }

        return $sanitized;
    }

    private static function warnFieldNameSanitizationOnce(string $original): void
    {
        if (self::$fieldNameSanitizationWarned) {
            return;
        }
        self::$fieldNameSanitizationWarned = true;

        self::safeErrorLog(sprintf(
            'AxiomHandler: sanitized field name "%s" (replaced "\\" with "__"). '
            .'Axiom rejects backslashes in field names; the most common cause is '
            .'logging an object whose class FQCN contains backslashes. Implement '
            .'JsonSerializable on the object or unwrap it before logging. '
            .'This warning fires once per process.',
            $original,
        ));
    }

    private static function warnFieldNameCollisionOnce(string $key): void
    {
        if (self::$fieldNameCollisionWarned) {
            return;
        }
        self::$fieldNameCollisionWarned = true;

        self::safeErrorLog(sprintf(
            'AxiomHandler: field name "%s" appeared in context/extra both '
            .'literally and as the result of sanitizing a backslash-containing '
            .'key in the same scope. The later occurrence has overwritten the '
            .'earlier value. This warning fires once per process.',
            $key,
        ));
    }

    /**
     * Wraps error_log so a custom error handler that promotes E_WARNING to an
     * exception (e.g. Laravel's, or a user-installed set_error_handler) cannot
     * crash the request from inside the log handler.
     */
    private static function safeErrorLog(string $message): void
    {
        try {
            error_log($message);
        } catch (\Throwable) {
            // Logging should never crash the app
        }
    }
}
