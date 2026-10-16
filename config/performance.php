<?php

return [
    'slow_query_ms' => (float) env('PERFORMANCE_SLOW_QUERY_MS', 100),
    'slow_request_ms' => (float) env('PERFORMANCE_SLOW_REQUEST_MS', 1000),
    'catalog_cache_seconds' => (int) env('CATALOG_CACHE_SECONDS', 30),
];
