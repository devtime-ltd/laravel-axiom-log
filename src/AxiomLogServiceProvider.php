<?php

namespace DevtimeLtd\LaravelAxiomLog;

use Illuminate\Support\ServiceProvider;
use Monolog\Processor\PsrLogMessageProcessor;

class AxiomLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/axiom.php', 'axiom');

        $channelName = config('axiom.channel_name', 'axiom');

        $this->app->make('config')->set("logging.channels.{$channelName}", [
            'driver' => 'monolog',
            'level' => config('logging.level', 'debug'),
            'handler' => AxiomHandler::class,
            'handler_with' => [
                'apiToken' => config('axiom.token'),
                'dataset' => config('axiom.dataset'),
                'host' => config('axiom.host'),
                'batchSize' => config('axiom.batch_size'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/axiom.php' => config_path('axiom.php'),
        ], 'axiom-log-config');
    }
}
