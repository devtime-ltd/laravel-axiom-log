<?php

namespace DevtimeLtd\LaravelAxiomLog;

use Illuminate\Support\ServiceProvider;

class AxiomLogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/log-request.php', 'log-request');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/log-request.php' => config_path('log-request.php'),
        ], 'log-request');
    }
}
