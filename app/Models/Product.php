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
        static::updating(function ($product): void {
            $previousStoreId = $product->getOriginal('store_id');

            if ($product->isDirty('store_id') && $previousStoreId) {
                MiniAppCatalogCache::forget($previousStoreId);
            }
        });

        // Cache clearing scoped to the specific store of the product
        static::saved(function ($product) {
            if ($product->store_id) {
                MiniAppCatalogCache::forget($product->store_id);
            }
        });

        static::deleted(function ($product) {
            if ($product->store_id) {
                MiniAppCatalogCache::forget($product->store_id);
            }
        });

        static::restored(function ($product) {
            if ($product->store_id) {
                MiniAppCatalogCache::forget($product->store_id);
            }
        });
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
