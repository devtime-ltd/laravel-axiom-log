<?php

return [

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

    'channel' => env('LOG_REQUESTS_CHANNEL'),

    'obfuscate_ip' => false, // false or callable, e.g. ObfuscateIp::level(2)

    'collect_queries' => true,

    'slow_query_threshold' => 100, // null to disable slow query collection

];
