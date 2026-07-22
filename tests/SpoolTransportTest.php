<?php

declare(strict_types=1);

use DevtimeLtd\LaravelAxiomLog\AxiomHandler;
use DevtimeLtd\LaravelAxiomLog\IngestClient;
use DevtimeLtd\LaravelAxiomLog\SpoolShipper;
use Monolog\Level;
use Monolog\LogRecord;

function makeRecord(string $message = 'hello'): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: $message,
        context: ['k' => 'v'],
        extra: [],
    );
}

function spoolDir(): string
{
    $dir = sys_get_temp_dir().'/axiom-spool-test-'.uniqid();
    mkdir($dir, 0775, true);

    return $dir;
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/axiom-spool-test-*/*') ?: [] as $f) {
        @unlink($f);
    }
});

test('spool transport writes ndjson lines and never touches http', function () {
    $dir = spoolDir();
    $handler = new class('token', 'ds', spool: $dir) extends AxiomHandler
    {
        public int $httpCalls = 0;

        public function __construct(string $t, string $d, string $spool)
        {
            parent::__construct(apiToken: $t, dataset: $d, transport: 'spool', spoolPath: $spool);
        }

        protected function executeRequest(string $url, string $json): array
        {
            $this->httpCalls++;

            return ['status' => 200, 'body' => '', 'error' => ''];
        }
    };

    $handler->handle(makeRecord('one'));
    $handler->handle(makeRecord('two'));
    $handler->flush();

    $file = $dir.'/ds.ndjson';
    expect(file_exists($file))->toBeTrue();
    $lines = array_values(array_filter(explode("\n", file_get_contents($file))));
    expect($lines)->toHaveCount(2)
        ->and(json_decode($lines[0], true)['message'])->toBe('one')
        ->and(json_decode($lines[1], true)['message'])->toBe('two')
        ->and($handler->httpCalls)->toBe(0);
});

test('shipper ships spooled events and removes the file on success', function () {
    $dir = spoolDir();
    file_put_contents($dir.'/ds.ndjson', json_encode(['message' => 'a'])."\n".json_encode(['message' => 'b'])."\n");

    $client = new class('t', 'ds') extends IngestClient
    {
        public array $payloads = [];

        public function send(string $json): array
        {
            $this->payloads[] = json_decode($json, true);

            return ['status' => 200, 'body' => '', 'error' => ''];
        }
    };

    $result = (new SpoolShipper($dir, $client))->ship();

    expect($result['shipped'])->toBe(2)
        ->and($result['failed'])->toBe(0)
        ->and($client->payloads)->toHaveCount(1)
        ->and($client->payloads[0])->toHaveCount(2)
        ->and(glob($dir.'/*'))->toBeEmpty();
});

test('shipper retains the file when ingest fails', function () {
    $dir = spoolDir();
    file_put_contents($dir.'/ds.ndjson', json_encode(['message' => 'a'])."\n");

    $client = new class('t', 'ds') extends IngestClient
    {
        public function send(string $json): array
        {
            return ['status' => 500, 'body' => 'boom', 'error' => ''];
        }
    };

    $result = (new SpoolShipper($dir, $client))->ship();

    expect($result['failed'])->toBe(1)
        ->and(glob($dir.'/*.shipping'))->toHaveCount(1);
});

test('shipper skips corrupt lines instead of blocking the spool', function () {
    $dir = spoolDir();
    file_put_contents($dir.'/ds.ndjson', "{not json\n".json_encode(['message' => 'ok'])."\n");

    $client = new class('t', 'ds') extends IngestClient
    {
        public array $payloads = [];

        public function send(string $json): array
        {
            $this->payloads[] = json_decode($json, true);

            return ['status' => 200, 'body' => '', 'error' => ''];
        }
    };

    $result = (new SpoolShipper($dir, $client))->ship();

    expect($result['shipped'])->toBe(1)
        ->and($client->payloads[0])->toHaveCount(1);
});

test('cap eviction drops oldest files first', function () {
    $dir = spoolDir();
    file_put_contents($dir.'/old.ndjson.x.shipping', str_repeat('a', 600));
    touch($dir.'/old.ndjson.x.shipping', time() - 100);
    file_put_contents($dir.'/ds.ndjson', json_encode(['message' => 'keep'])."\n");

    $client = new class('t', 'ds') extends IngestClient
    {
        public function send(string $json): array
        {
            return ['status' => 200, 'body' => '', 'error' => ''];
        }
    };

    $result = (new SpoolShipper($dir, $client, maxSpoolBytes: 500))->ship();

    expect($result['dropped'])->toBe(1)
        ->and($result['shipped'])->toBe(1);
});
