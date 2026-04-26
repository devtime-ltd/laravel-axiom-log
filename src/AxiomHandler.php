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
            $event['context'] = $this->normalizer->normalizeValue($record->context);
        }

        if (! empty($record->extra)) {
            $event['extra'] = $this->normalizer->normalizeValue($record->extra);
        }

        return $event;
    }
}
