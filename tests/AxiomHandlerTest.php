<?php

use DevtimeLtd\LaravelAxiomLog\AxiomHandler;
use DevtimeLtd\LaravelAxiomLog\Tests\Fixtures\PlainNamespacedObject;
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

    it('removes handlers from the static registry on destruction', function () {
        $reflection = new ReflectionProperty(AxiomHandler::class, 'instances');

        $handler = makeAxiomHandler();
        $id = spl_object_id($handler);
        expect($reflection->getValue())->toHaveKey($id);

        unset($handler);

        // Inspect the raw static array directly so the WeakReference pruning
        // inside instances() cannot mask a missing destructor unset.
        expect($reflection->getValue())->not->toHaveKey($id);
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

    it('captures the context() array on a throwable', function () {
        $handler = makeAxiomHandler();
        $exception = new class('boom') extends RuntimeException
        {
            public function context(): array
            {
                return ['order_id' => 123, 'user_id' => 7];
            }
        };

        $handler->handle(makeLogRecord('failed', context: ['exception' => $exception]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];

        expect($event['context']['exception']['context'])
            ->toBe(['order_id' => 123, 'user_id' => 7]);
    });

    it('omits the context key when context() returns an empty array', function () {
        $handler = makeAxiomHandler();
        $exception = new class('boom') extends RuntimeException
        {
            public function context(): array
            {
                return [];
            }
        };

        $handler->handle(makeLogRecord('failed', context: ['exception' => $exception]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];

        expect($event['context']['exception'])->not->toHaveKey('context');
    });

    it('omits the context key when the throwable has no context() method', function () {
        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('failed', context: ['exception' => new RuntimeException('boom')]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];

        expect($event['context']['exception'])->not->toHaveKey('context');
    });

    it('swallows exceptions thrown by context() and omits the field', function () {
        $handler = makeAxiomHandler();
        $exception = new class('boom') extends RuntimeException
        {
            public function context(): array
            {
                throw new RuntimeException('context blew up');
            }
        };

        $handler->handle(makeLogRecord('failed', context: ['exception' => $exception]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];

        expect($event['context']['exception'])->not->toHaveKey('context');
    });

    it('captures context() on previous exceptions in the chain', function () {
        $handler = makeAxiomHandler();
        $inner = new class('inner') extends RuntimeException
        {
            public function context(): array
            {
                return ['stage' => 'payment'];
            }
        };
        $outer = new RuntimeException('outer', 0, $inner);

        $handler->handle(makeLogRecord('failed', context: ['exception' => $outer]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];

        expect($event['context']['exception']['previous']['context'])
            ->toBe(['stage' => 'payment']);
    });

    it('normalizes non-JSON-safe values returned by context() so flush does not drop the batch', function () {
        $handler = makeAxiomHandler();
        $resource = fopen('php://memory', 'r');
        $exception = new class('boom', $resource) extends RuntimeException
        {
            public function __construct(string $message, private $resource)
            {
                parent::__construct($message);
            }

            public function context(): array
            {
                return ['handle' => $this->resource];
            }
        };

        $handler->handle(makeLogRecord('failed', context: ['exception' => $exception]));
        $handler->close();
        fclose($resource);

        expect($handler->sent)->toHaveCount(1);
        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context']['exception']['context'])->toHaveKey('handle');
        expect($event['context']['exception']['context']['handle'])->toBeString();
    });

    it('truncates cyclic context() data via depth budget without exhausting memory', function () {
        $handler = makeAxiomHandler();
        $exception = new class('boom') extends RuntimeException
        {
            public function context(): array
            {
                return ['self' => $this];
            }
        };

        $handler->handle(makeLogRecord('failed', context: ['exception' => $exception]));
        $handler->close();

        expect($handler->sent)->toHaveCount(1);
        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context']['exception'])->toHaveKey('context');
    });

    it('preserves the existing wire shape (level, _time, channel)', function () {
        $handler = makeAxiomHandler();
        $exception = new class('boom') extends RuntimeException
        {
            public function context(): array
            {
                return ['ok' => true];
            }
        };

        $handler->handle(makeLogRecord('failed', context: ['exception' => $exception]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];

        expect($event)->toHaveKey('_time');
        expect($event['level'])->toBeString();
        expect($event)->toHaveKey('channel');
        expect($event['context']['exception'])
            ->toHaveKey('class')
            ->toHaveKey('message')
            ->toHaveKey('code')
            ->toHaveKey('file');
    });
});

describe('JsonSerializable normalization', function () {
    it('unwraps JsonSerializable values to their jsonSerialize() result', function () {
        $handler = makeAxiomHandler();
        $collector = new class implements JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['member:abc', 'project:xyz'];
            }
        };

        $handler->handle(makeLogRecord('with collector', context: ['sigils' => $collector]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context']['sigils'])->toBe(['member:abc', 'project:xyz']);
    });

    it('does not wrap JsonSerializable values with the class name', function () {
        $handler = makeAxiomHandler();
        $serializable = new class implements JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['ok' => true];
            }
        };

        $handler->handle(makeLogRecord('flat', context: ['data' => $serializable]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context']['data'])->toBe(['ok' => true]);
    });

    it('unwraps JsonSerializable values nested inside arrays', function () {
        $handler = makeAxiomHandler();
        $serializable = new class implements JsonSerializable
        {
            public function jsonSerialize(): string
            {
                return 'unwrapped';
            }
        };

        $handler->handle(makeLogRecord('nested', context: ['outer' => ['inner' => $serializable]]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context']['outer']['inner'])->toBe('unwrapped');
    });

    it('still routes Throwables through the structured exception path', function () {
        $handler = makeAxiomHandler();
        $exception = new RuntimeException('boom');

        $handler->handle(makeLogRecord('failed', context: ['exception' => $exception]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context']['exception'])
            ->toHaveKey('class', 'RuntimeException')
            ->toHaveKey('message', 'boom');
    });
});

describe('field name sanitization', function () {
    beforeEach(function () {
        $reflection = new ReflectionProperty(AxiomHandler::class, 'fieldNameSanitizationWarned');
        $reflection->setValue(null, false);
    });

    it('replaces backslashes in user-supplied keys with double underscore', function () {
        $handler = makeAxiomHandler();

        $handler->handle(makeLogRecord('bad key', context: ['App\\Service\\Foo' => 'v']));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context'])
            ->toHaveKey('App__Service__Foo', 'v')
            ->not->toHaveKey('App\\Service\\Foo');
    });

    it('replaces backslashes in keys at any nesting depth', function () {
        $handler = makeAxiomHandler();

        $handler->handle(makeLogRecord('nested', context: ['outer' => ['App\\Foo' => 'v']]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context']['outer'])
            ->toHaveKey('App__Foo', 'v')
            ->not->toHaveKey('App\\Foo');
    });

    it('sanitizes class-name keys produced when Monolog wraps non-JsonSerializable objects', function () {
        $handler = makeAxiomHandler();

        $handler->handle(makeLogRecord('plain', context: ['data' => new PlainNamespacedObject('hello')]));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        $sanitizedKey = 'DevtimeLtd__LaravelAxiomLog__Tests__Fixtures__PlainNamespacedObject';
        expect($event['context']['data'])->toHaveKey($sanitizedKey);
        expect($event['context']['data'][$sanitizedKey])->toBe(['value' => 'hello']);
    });

    it('does not modify backslashes inside values', function () {
        $handler = makeAxiomHandler();

        $handler->handle(makeLogRecord('value', context: ['class' => 'App\\Service\\Foo']));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context']['class'])->toBe('App\\Service\\Foo');
    });

    it('also sanitizes keys in extra', function () {
        $handler = makeAxiomHandler();

        $handler->handle(makeLogRecord('with extra', extra: ['App\\Tag' => 'v']));
        $handler->close();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['extra'])
            ->toHaveKey('App__Tag', 'v')
            ->not->toHaveKey('App\\Tag');
    });

    it('flips the warn-once flag when sanitization fires', function () {
        $reflection = new ReflectionProperty(AxiomHandler::class, 'fieldNameSanitizationWarned');
        expect($reflection->getValue())->toBeFalse();

        $handler = makeAxiomHandler();
        $handler->handle(makeLogRecord('first', context: ['App\\Foo' => 'v']));
        $handler->handle(makeLogRecord('second', context: ['App\\Bar' => 'v']));
        $handler->close();

        expect($reflection->getValue())->toBeTrue();
    });

    it('still sanitizes but does not warn when warnOnSanitization is false', function () {
        $handler = new FakeAxiomHandler(
            apiToken: 'test-token',
            dataset: 'test-dataset',
            warnOnSanitization: false,
        );
        $handler->handle(makeLogRecord('quiet', context: ['App\\Foo' => 'v']));
        $handler->close();

        $reflection = new ReflectionProperty(AxiomHandler::class, 'fieldNameSanitizationWarned');
        expect($reflection->getValue())->toBeFalse();

        $event = json_decode($handler->sent[0]['json'], true)[0];
        expect($event['context'])->toHaveKey('App__Foo', 'v');
    });

    it('produces a payload with no backslashes in any field name', function () {
        $handler = makeAxiomHandler();

        $handler->handle(makeLogRecord(
            'mixed',
            context: ['data' => new PlainNamespacedObject('x')],
            extra: ['App\\Tag' => 'v'],
        ));
        $handler->close();

        $payload = $handler->sent[0]['json'];
        $event = json_decode($payload, true)[0];

        $assertNoBackslashKeys = function (array $arr) use (&$assertNoBackslashKeys) {
            foreach ($arr as $key => $value) {
                expect($key)->not->toContain('\\');
                if (is_array($value)) {
                    $assertNoBackslashKeys($value);
                }
            }
        };
        $assertNoBackslashKeys($event);
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
