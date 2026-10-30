<?php

namespace App\Models;

use App\Models\Traits\BelongsToStore;
use App\Services\MiniAppCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes, BelongsToStore;

    protected static function booted(): void
    {
        // Cache clearing on product changes
        static::saved(fn () => MiniAppCatalogCache::forget());
        static::deleted(fn () => MiniAppCatalogCache::forget());
        static::restored(fn () => MiniAppCatalogCache::forget());
    }

    protected $fillable = [
        'store_id',
        'category_id',
        'name',
        'description',
        'price',
        'image_url',
        'image_public_id',
        'is_available',
        'sort_order',
        'requires_sugar',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_available' => 'boolean',
        'requires_sugar' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}
