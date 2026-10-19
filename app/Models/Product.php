<?php

namespace App\Models;

use App\Services\MiniAppCatalogCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::saved(fn () => MiniAppCatalogCache::forget());
        static::deleted(fn () => MiniAppCatalogCache::forget());
        static::restored(fn () => MiniAppCatalogCache::forget());
    }

    protected $fillable = [
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
        'requires_sugar' => 'boolean', // 👈 This ensures true/false is handled correctly
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
