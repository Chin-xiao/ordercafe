<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;

class ApiRequestMetrics
{
    private bool $active = false;

    private int $queryCount = 0;

    private float $queryDurationMs = 0;

    private int $slowQueryCount = 0;

    private array $firstQueryByConnection = [];

    private array $slowQueries = [];

    public function begin(): void
    {
        $this->active = true;
        $this->queryCount = 0;
        $this->queryDurationMs = 0;
        $this->slowQueryCount = 0;
        $this->firstQueryByConnection = [];
        $this->slowQueries = [];
    }

    public function record(QueryExecuted $query): void
    {
        if (! $this->active) {
            return;
        }

        $duration = (float) $query->time;
        $this->queryCount++;
        $this->queryDurationMs += $duration;
        $this->firstQueryByConnection[$query->connectionName] ??= round($duration, 2);

        if ($duration >= config('performance.slow_query_ms', 100)) {
            $this->slowQueryCount++;
            $this->slowQueries[] = [
                'duration_ms' => round($duration, 2),
                'connection' => $query->connectionName,
                'fingerprint' => hash('sha256', $query->sql),
            ];
            usort($this->slowQueries, fn (array $left, array $right): int => $right['duration_ms'] <=> $left['duration_ms']);
            $this->slowQueries = array_slice($this->slowQueries, 0, 5);
        }
    }

    public function finish(): array
    {
        $this->active = false;

        return [
            'query_count' => $this->queryCount,
            'query_duration_ms' => round($this->queryDurationMs, 2),
            'first_query_by_connection_ms' => $this->firstQueryByConnection,
            'slow_query_count' => $this->slowQueryCount,
            'slow_queries' => $this->slowQueries,
        ];
    }
}
