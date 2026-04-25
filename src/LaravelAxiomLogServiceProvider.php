<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog;

use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;
use Monolog\Logger as MonologLogger;

/**
 * Flushes any AxiomHandler buffers on long-lived process boundaries (queue
 * jobs, worker shutdown, Octane request/task/tick termination) so low-volume
 * records are not retained indefinitely waiting on the batch threshold.
 */
class LaravelAxiomLogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $events = $this->app['events'];

        $flush = function (): void {
            $this->flushAxiomHandlers();
        };

        $events->listen(JobProcessed::class, $flush);
        $events->listen(JobExceptionOccurred::class, $flush);
        $events->listen(WorkerStopping::class, $flush);

        foreach ([RequestTerminated::class, TaskTerminated::class, TickTerminated::class] as $octaneEvent) {
            if (class_exists($octaneEvent)) {
                $events->listen($octaneEvent, $flush);
            }
        }
    }

    public function flushAxiomHandlers(): void
    {
        $log = $this->app->bound('log') ? $this->app->make('log') : null;
        if (! $log instanceof LogManager) {
            return;
        }

        foreach ($log->getChannels() as $channel) {
            $monolog = method_exists($channel, 'getLogger') ? $channel->getLogger() : null;
            if (! $monolog instanceof MonologLogger) {
                continue;
            }

            foreach ($monolog->getHandlers() as $handler) {
                if ($handler instanceof AxiomHandler) {
                    $handler->flush();
                }
            }
        }
    }
}
