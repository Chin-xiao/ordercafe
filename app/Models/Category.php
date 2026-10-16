<?php

namespace App\Models;

use App\Services\MiniAppCatalogCache;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => MiniAppCatalogCache::forget());
        static::deleted(fn () => MiniAppCatalogCache::forget());
    }

    protected $fillable = [
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
