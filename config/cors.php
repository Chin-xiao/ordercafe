<?php

$defaultOrigins = [
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'https://ordercafe-front.onrender.com',
];

if ($appUrl = env('APP_URL')) {
    $defaultOrigins[] = rtrim($appUrl, '/');
}

$allowedOrigins = array_values(array_unique(array_filter(
    array_map('trim', preg_split('/\s*,\s*/', (string) env('CORS_ALLOWED_ORIGINS', implode(',', $defaultOrigins)))),
    fn (string $origin) => $origin !== ''
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [
        '^https?://(localhost|127\.0\.0\.1)(:\d+)?$',
        '^https://ordercafe-front\.onrender\.com$',
    ],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
