<?php

use DevtimeLtd\LaravelAxiomLog\AxiomHandler;
use Monolog\Level;
use Monolog\LogRecord;

class FakeAxiomHandler extends AxiomHandler
{
    /** @var list<array{url: string, json: string, shuttingDown: bool}> */
    public array $sent = [];

    protected function send(string $url, string $json): void
    {
        $reflection = new ReflectionProperty(AxiomHandler::class, 'shuttingDown');
        $this->sent[] = ['url' => $url, 'json' => $json, 'shuttingDown' => $reflection->getValue($this)];
    }
}

function makeAxiomHandler(int $batchSize = 50): FakeAxiomHandler
{
    return new FakeAxiomHandler(
        apiToken: 'test-token',
        dataset: 'test-dataset',
        host: 'https://api.axiom.co',
        batchSize: $batchSize,
    );
}

function makeLogRecord(string $message, Level $level = Level::Info, array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2026-04-09T12:00:00+00:00'),
        channel: 'test',
        level: $level,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

describe('batching', function () {
    it('does not send until close', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('hello'));

        expect($handler->sent)->toBeEmpty();

        $handler->close();

        expect($handler->sent)->toHaveCount(1);
    });

    it('flushes when batch size is reached', function () {
        $handler = makeAxiomHandler(batchSize: 3);

        $handler->handle(makeLogRecord('one'));
        $handler->handle(makeLogRecord('two'));
        expect($handler->sent)->toBeEmpty();

        $handler->handle(makeLogRecord('three'));
        expect($handler->sent)->toHaveCount(1);

        $payload = json_decode($handler->sent[0]['json'], true);
        expect($payload)->toHaveCount(3);
    });

    it('sends remaining records on close after a mid-request flush', function () {
        $handler = makeAxiomHandler(batchSize: 2);

        $handler->handle(makeLogRecord('one'));
        $handler->handle(makeLogRecord('two'));   // triggers flush
        $handler->handle(makeLogRecord('three'));
        $handler->close();                      // flushes remainder

        expect($handler->sent)->toHaveCount(2);

        $first = json_decode($handler->sent[0]['json'], true);
        $second = json_decode($handler->sent[1]['json'], true);
        expect($first)->toHaveCount(2);
        expect($second)->toHaveCount(1);
    });

    it('does not send when there are no records', function () {
        $handler = makeAxiomHandler();
        $handler->close();

        expect($handler->sent)->toBeEmpty();
    });
});

describe('record formatting', function () {
    it('includes standard fields', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('test message', Level::Error));
        $handler->close();

        $payload = json_decode($handler->sent[0]['json'], true);
        $event = $payload[0];

        expect($event)
            ->toHaveKey('_time', '2026-04-09T12:00:00+00:00')
            ->toHaveKey('level', 'Error')
            ->toHaveKey('message', 'test message')
            ->toHaveKey('channel', 'test');
    });

    it('includes context when present', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('with context', context: ['user_id' => 42]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context'])->toBe(['user_id' => 42]);
    });

    it('includes extra when present', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('with extra', extra: ['ip' => '127.0.0.1']));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['extra'])->toBe(['ip' => '127.0.0.1']);
    });

    it('omits context and extra when empty', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('bare'));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event)->not->toHaveKey('context');
        expect($event)->not->toHaveKey('extra');
    });

    it('substitutes invalid UTF-8 instead of crashing', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('valid message', context: ['data' => "bad byte: \x80"]));
        $handler->close();

        expect($handler->sent)->toHaveCount(1);
        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['message'])->toBe('valid message');
    });
});

describe('endpoint construction', function () {
    it('builds the correct ingest URL', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('hello'));
        $handler->close();

        expect($handler->sent[0]['url'])
            ->toBe('https://api.axiom.co/v1/datasets/test-dataset/ingest');
    });

    it('handles trailing slash on host', function () {
        $handler = new FakeAxiomHandler(
            apiToken: 'test-token',
            dataset: 'test-dataset',
            host: 'https://api.axiom.co/',
            batchSize: 50,
        );
        $handler->handle(makeLogRecord('hello'));
        $handler->close();

        expect($handler->sent[0]['url'])
            ->toBe('https://api.axiom.co/v1/datasets/test-dataset/ingest');
    });
});

describe('resilience', function () {
    it('renders resources in context as a string instead of crashing', function () {
        $handler = makeAxiomHandler();
        $resource = fopen('php://memory', 'r');
        $handler->handle(makeLogRecord('with resource', context: ['bad' => $resource]));
        $handler->close();
        fclose($resource);

        expect($handler->sent)->toHaveCount(1);
        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context']['bad'])->toContain('resource');
    });

    it('does not double-send on repeated close', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('hello'));
        $handler->close();
        $handler->close();

        expect($handler->sent)->toHaveCount(1);
    });

    it('flushes buffered records when the handler is destructed', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('orphan'));
        expect($handler->sent)->toBeEmpty();

        $handler->__destruct();

        expect($handler->sent)->toHaveCount(1);
        expect($handler->sent[0]['shuttingDown'])->toBeTrue();
        $payload = json_decode($handler->sent[0]['json'], true);
        expect($payload)->toHaveCount(1);
        expect($payload[0]['message'])->toBe('orphan');
    });

    it('does not mark non-destructor flushes as shutting down', function () {
        $handler = makeAxiomHandler(batchSize: 1);
        $handler->handle(makeLogRecord('eager'));

        expect($handler->sent)->toHaveCount(1);
        expect($handler->sent[0]['shuttingDown'])->toBeFalse();
    });
});

describe('throwable normalization', function () {
    it('normalizes a throwable in context to a structured array', function () {
        $handler = makeAxiomHandler();
        $exception = new RuntimeException('boom', 42);
        $handler->handle(makeLogRecord('failed', context: ['exception' => $exception]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];

        expect($event['context']['exception'])
            ->toHaveKey('class', 'RuntimeException')
            ->toHaveKey('message', 'boom')
            ->toHaveKey('code', 42);
        expect($event['context']['exception']['file'])->toContain(__FILE__);
    });

    it('normalizes nested throwables in context', function () {
        $handler = makeAxiomHandler();
        $exception = new LogicException('inner');
        $handler->handle(makeLogRecord('failed', context: ['outer' => ['inner' => $exception]]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];

        expect($event['context']['outer']['inner'])
            ->toHaveKey('class', 'LogicException')
            ->toHaveKey('message', 'inner');
    });

    it('includes previous exception chain', function () {
        $handler = makeAxiomHandler();
        $inner = new LogicException('inner cause');
        $outer = new RuntimeException('outer effect', 0, $inner);
        $handler->handle(makeLogRecord('failed', context: ['exception' => $outer]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];

        expect($event['context']['exception']['previous'])
            ->toHaveKey('class', 'LogicException')
            ->toHaveKey('message', 'inner cause');
    });

    it('normalizes throwables in extra too', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('failed', extra: ['err' => new RuntimeException('from extra')]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];

        expect($event['extra']['err'])
            ->toHaveKey('class', 'RuntimeException')
            ->toHaveKey('message', 'from extra');
    });
});

describe('level filtering', function () {
    it('respects the minimum log level', function () {
        $handler = new FakeAxiomHandler(
            apiToken: 'test-token',
            dataset: 'test-dataset',
            level: Level::Warning,
        );

        $handler->handle(makeLogRecord('debug msg', Level::Debug));
        $handler->handle(makeLogRecord('info msg', Level::Info));
        $handler->handle(makeLogRecord('warning msg', Level::Warning));
        $handler->handle(makeLogRecord('error msg', Level::Error));
        $handler->close();

        $payload = json_decode($handler->sent[0]['json'], true);
        expect($payload)->toHaveCount(2);
        expect($payload[0]['message'])->toBe('warning msg');
        expect($payload[1]['message'])->toBe('error msg');
    });
});
