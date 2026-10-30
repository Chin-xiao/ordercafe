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
        // Cache clearing on category changes
        static::saved(fn () => MiniAppCatalogCache::forget());
        static::deleted(fn () => MiniAppCatalogCache::forget());
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
