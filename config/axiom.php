<?php

use DevtimeLtd\LaravelAxiomLog\AxiomHandler;

return [

    /*
    |--------------------------------------------------------------------------
    | Axiom Connection
    |--------------------------------------------------------------------------
    */

    'channel_name' => env('AXIOM_LOG_CHANNEL_NAME', 'axiom'),

    'token' => env('AXIOM_LOG_TOKEN', ''),
    'dataset' => env('AXIOM_LOG_DATASET', ''),
    'host' => env('AXIOM_LOG_HOST', AxiomHandler::DEFAULT_HOST),
    'batch_size' => (int) env('AXIOM_LOG_BATCH_SIZE', AxiomHandler::DEFAULT_BATCH_SIZE),

    /*
    |--------------------------------------------------------------------------
    | Request Logging
    |--------------------------------------------------------------------------
    |
    | Set the channel to enable per-request logging via the LogRequest
    | middleware. Supports comma-separated channels for Log::stack().
    | Leave null to disable.
    |
    */

    'request_logging' => [
        'channel' => env('LOG_REQUESTS_CHANNEL'),
        'obfuscate_ip' => false, // false or callable, e.g. ObfuscateIp::level(2)
        'collect_queries' => true,
        'slow_query_threshold' => 100, // null to disable slow query collection
    ],

];
