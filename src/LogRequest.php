<?php

namespace DevtimeLtd\LaravelAxiomLog;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LogRequest
{
    const DEFAULT_COLLECT_QUERIES = true;

    const DEFAULT_SLOW_QUERY_THRESHOLD = 100;

    /** @var (Closure(Request, ?Response, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $usingCallback = null;

    /** @var (Closure(Request, ?Response, array<string, mixed>): array<string, mixed>)|null */
    private static ?Closure $extendCallback = null;

    private int $queryCount = 0;

    private float $queryTotalMs = 0;

    /** @var list<array{sql: string, duration_ms: float, connection: string}> */
    private array $slowQueries = [];

    /**
     * Replace the default log entry builder.
     *
     * The callback receives the request, response (null on exception),
     * and a measurements array. Return the entry array to log.
     *
     * @param  (Closure(Request, ?Response, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function using(?Closure $callback): void
    {
        static::$usingCallback = $callback;
    }

    /**
     * Register a callback to extend the log entry with additional fields.
     *
     * @param  (Closure(Request, ?Response, array<string, mixed>): array<string, mixed>)|null  $callback
     */
    public static function extend(?Closure $callback): void
    {
        static::$extendCallback = $callback;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('axiom.request_logging.channel')) {
            return $next($request);
        }

        $start = microtime(true);

        if (config('axiom.request_logging.collect_queries', self::DEFAULT_COLLECT_QUERIES)) {
            $slowThreshold = config('axiom.request_logging.slow_query_threshold', self::DEFAULT_SLOW_QUERY_THRESHOLD);

            DB::listen(function (QueryExecuted $query) use ($slowThreshold) {
                $this->queryCount++;
                $this->queryTotalMs += $query->time;

                if ($slowThreshold !== null && $query->time >= $slowThreshold) {
                    $this->slowQueries[] = [
                        'sql' => $query->sql,
                        'duration_ms' => round($query->time, 2),
                        'connection' => $query->connectionName,
                    ];
                }
            });
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->log($request, null, microtime(true) - $start);

            throw $e;
        }

        $this->log($request, $response, microtime(true) - $start);

        return $response;
    }

    private function log(Request $request, ?Response $response, float $elapsed): void
    {
        try {
            $measurements = [
                'duration_ms' => round($elapsed * 1000, 2),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            ];

            if (config('axiom.request_logging.collect_queries', self::DEFAULT_COLLECT_QUERIES)) {
                $measurements['query_count'] = $this->queryCount;
                $measurements['query_total_ms'] = round($this->queryTotalMs, 2);

                if ($this->slowQueries) {
                    $measurements['slow_queries'] = $this->slowQueries;
                }
            }

            if (static::$usingCallback) {
                $entry = (static::$usingCallback)($request, $response, $measurements);
            } else {
                $entry = $this->buildEntry($request, $response, $measurements);
            }

            if (static::$extendCallback) {
                $entry = (static::$extendCallback)($request, $response, $entry);
            }

            $channels = explode(',', config('axiom.request_logging.channel'));
            $logger = count($channels) === 1
                ? Log::channel($channels[0])
                : Log::stack($channels);
            $logger->info('request', $entry);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEntry(Request $request, ?Response $response, array $measurements): array
    {
        $ip = $request->ip();
        $maskIp = config('axiom.request_logging.obfuscate_ip');

        if ($maskIp) {
            $ip = call_user_func($maskIp, $ip);
        }

        try {
            if ($response instanceof BinaryFileResponse) {
                $responseSize = $response->getFile()->getSize() ?: null;
            } elseif ($response instanceof StreamedResponse || ! $response) {
                $responseSize = null;
            } else {
                $responseSize = strlen($response->getContent());
            }
        } catch (\Throwable) {
            $responseSize = null;
        }

        $entry = [
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'route' => $request->route()?->getName(),
            'status' => $response?->getStatusCode(),
            'content_type' => $response?->headers->get('Content-Type'),
            'response_size' => $responseSize,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'ip' => $ip,
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('referer'),
        ];

        $routeParams = $request->route()?->originalParameters();
        if ($routeParams) {
            $entry['route_params'] = $routeParams;
        }

        return array_merge($entry, $measurements);
    }
}
