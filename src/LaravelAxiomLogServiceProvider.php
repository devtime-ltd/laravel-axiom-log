<?php

declare(strict_types=1);

namespace DevtimeLtd\LaravelAxiomLog;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\ServiceProvider;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\TickTerminated;

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

        $flush = static function (): void {
            foreach (AxiomHandler::instances() as $handler) {
                $handler->flush();
            }
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
}
