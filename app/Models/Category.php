<?php

namespace App\Models;

use App\Models\Traits\BelongsToStore;
use App\Services\MiniAppCatalogCache;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use BelongsToStore;

    protected static function booted(): void
    {
        static::saved(function ($category): void {
            if ($category->store_id) {
                MiniAppCatalogCache::forget($category->store_id);
            }
        });

        static::deleted(function ($category): void {
            if ($category->store_id) {
                MiniAppCatalogCache::forget($category->store_id);
            }
        });

        static::registerModelEvent('restored', function ($category): void {
            if ($category->store_id) {
                MiniAppCatalogCache::forget($category->store_id);
            }
        });
    }

    protected $fillable = [
        'store_id',
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
