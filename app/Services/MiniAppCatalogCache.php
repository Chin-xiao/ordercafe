<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class MiniAppCatalogCache
{
    public const PRODUCTS_KEY = 'mini-app:catalog:products:v1';

    public const CATEGORIES_KEY = 'mini-app:catalog:categories:v1';

    public static function remember(string $key, int $storeId, callable $callback): mixed
    {
        // Scope the cache key uniquely per store
        $scopedKey = "{$key}:store:{$storeId}";

        return Cache::store('file')->remember(
            $scopedKey,
            now()->addSeconds(config('performance.catalog_cache_seconds', 30)),
            $callback
        );
    }

    public static function forget(int $storeId): void
    {
        // Clear only the specified store's cache
        Cache::store('file')->forget(self::PRODUCTS_KEY . ":store:{$storeId}");
        Cache::store('file')->forget(self::CATEGORIES_KEY . ":store:{$storeId}");
    }
}
