<?php

namespace App\Models;

use App\Services\MiniAppCatalogCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
class Product extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        // 1. Keep your existing cache clearing logic
        static::saved(fn () => MiniAppCatalogCache::forget());
        static::deleted(fn () => MiniAppCatalogCache::forget());
        static::restored(fn () => MiniAppCatalogCache::forget());

        // 2. Automatically filter queries by store_id if logged in as a store owner
        static::addGlobalScope('store', function (Builder $builder) {
            if (Auth::check()) {
                $user = Auth::user();
                if ($user->role === 'store_owner' && $user->store_id) {
                    $builder->where('products.store_id', $user->store_id);
                }
            }
        });

        // 3. Automatically assign store_id when a store owner creates a product
        static::creating(function ($model) {
            if (Auth::check()) {
                $user = Auth::user();
                if ($user->role === 'store_owner' && empty($model->store_id)) {
                    $model->store_id = $user->store_id;
                }
            }
        });
    }

    protected $fillable = [
        'store_id', // <-- Added here
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

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
