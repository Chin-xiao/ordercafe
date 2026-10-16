<?php

namespace App\Http\Middleware;

use App\Support\ApiRequestMetrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordApiPerformance
{
    public function __construct(private ApiRequestMetrics $metrics) {}

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $this->metrics->begin();

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $queryMetrics = $this->metrics->finish();
            Log::error('API request failed before a response was produced.', [
                'method' => $request->method(),
                'path' => $request->path(),
                'exception' => $exception::class,
                'request_duration_ms' => $this->durationMs($startedAt),
                ...$queryMetrics,
            ]);

            throw $exception;
        }

        $duration = $this->durationMs($startedAt);
        $queryMetrics = $this->metrics->finish();
        $status = $response->getStatusCode();
        $context = [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $status,
            'request_duration_ms' => $duration,
            'response_bytes' => strlen((string) $response->getContent()),
            ...$queryMetrics,
        ];

        if ($status >= 500) {
            Log::error('API request returned a server error.', $context);
        } elseif ($status >= 400) {
            Log::warning('API request was rejected.', $context);
        } elseif ($duration >= config('performance.slow_request_ms', 1000)
            || $queryMetrics['slow_queries'] !== []) {
            Log::warning('Slow API request observed.', $context);
        } else {
            Log::info('API request performance.', $context);
        }

        return $response;
    }

    private function durationMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
