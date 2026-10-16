<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class MiniAppCatalogCache
{
    public const PRODUCTS_KEY = 'mini-app:catalog:products:v1';

    public const CATEGORIES_KEY = 'mini-app:catalog:categories:v1';

    public static function remember(string $key, callable $callback): mixed
    {
        return Cache::store('file')->remember(
            $key,
            now()->addSeconds(config('performance.catalog_cache_seconds', 30)),
            $callback
        );
    }

    public static function forget(): void
    {
        Cache::store('file')->forget(self::PRODUCTS_KEY);
        Cache::store('file')->forget(self::CATEGORIES_KEY);
    }
}
