<?php

use DevtimeLtd\LaravelAxiomLog\AxiomHandler;
use DevtimeLtd\LaravelAxiomLog\LaravelAxiomLogServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;
use Monolog\Logger as MonologLogger;

class RecordingAxiomHandler extends AxiomHandler
{
    /** @var list<array{url: string, json: string}> */
    public array $sent = [];

    protected function send(string $url, string $json): void
    {
        $this->sent[] = ['url' => $url, 'json' => $json];
    }
}

function registerRecordingChannel(string $name = 'axiom'): RecordingAxiomHandler
{
    $handler = new RecordingAxiomHandler(
        apiToken: 'test-token',
        dataset: 'test-dataset',
    );

    Log::extend('recording-axiom', fn () => new MonologLogger($name, [$handler]));

    config()->set("logging.channels.$name", [
        'driver' => 'recording-axiom',
    ]);

    Log::channel($name);

    return $handler;
}

it('flushes after a job is processed', function () {
    $handler = registerRecordingChannel();
    Log::channel('axiom')->info('mid-job event');

    expect($handler->sent)->toBeEmpty();

    Event::dispatch(new JobProcessed('sync', new class
    {
        public function getJobId(): string
        {
            return '1';
        }
    }));

    expect($handler->sent)->toHaveCount(1);
});

it('flushes after a job exception', function () {
    $handler = registerRecordingChannel();
    Log::channel('axiom')->error('boom');

    Event::dispatch(new JobExceptionOccurred('sync', new class
    {
        public function getJobId(): string
        {
            return '2';
        }
    }, new RuntimeException('boom')));

    expect($handler->sent)->toHaveCount(1);
});

it('flushes when the worker stops', function () {
    $handler = registerRecordingChannel();
    Log::channel('axiom')->info('orphan');

    Event::dispatch(new WorkerStopping(0, new WorkerOptions));

    expect($handler->sent)->toHaveCount(1);
});

it('does not error when no axiom channels are resolved', function () {
    app(LaravelAxiomLogServiceProvider::class, ['app' => app()])
        ->flushAxiomHandlers();
})->throwsNoExceptions();

it('flushes after an Octane request terminates', function () {
    $handler = registerRecordingChannel();
    Log::channel('axiom')->info('octane request');

    Event::dispatch(new RequestTerminated(
        app: app(),
        sandbox: app(),
        request: Request::create('/'),
        response: new Response,
    ));

    expect($handler->sent)->toHaveCount(1);
});

it('flushes after an Octane task terminates', function () {
    $handler = registerRecordingChannel();
    Log::channel('axiom')->info('octane task');

    Event::dispatch(new TaskTerminated(
        app: app(),
        sandbox: app(),
        data: null,
        result: null,
    ));

    expect($handler->sent)->toHaveCount(1);
});

it('flushes after an Octane tick terminates', function () {
    $handler = registerRecordingChannel();
    Log::channel('axiom')->info('octane tick');

    Event::dispatch(new TickTerminated(
        app: app(),
        sandbox: app(),
    ));

    expect($handler->sent)->toHaveCount(1);
});
