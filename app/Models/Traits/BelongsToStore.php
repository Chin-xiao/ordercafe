<?php

namespace App\Models\Traits;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Provides automatic multi-tenant scoping for any model with a `store_id` column.
 *
 * 1. Adds a global scope so store_owners only see their own store's records.
 * 2. Automatically stamps `store_id` when a store_owner creates a record.
 *
 * Usage: `use BelongsToStore;` in your model and ensure `store_id` is in `$fillable`.
 */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        // 1. Automatically filter queries so store owners only see their own store's data
        static::addGlobalScope('store', function (Builder $builder) {
            $user = Auth::user();

            if ($user && $user->role === 'store_owner') {
                if ($user->store_id) {
                    $builder->where($builder->getModel()->getTable() . '.store_id', $user->store_id);
                } else {
                    // Fail-safe: if store_owner has no store_id, ensure they see 0 records instead of global data
                    $builder->whereRaw('1 = 0');
                }
            }
            // Super admins have no scope restrictions and can view all data globally
        });

        // 2. Automatically stamp the store_id when a store owner creates a new record
        static::creating(function ($model) {
            $user = Auth::user();

            if ($user && $user->role === 'store_owner' && empty($model->store_id) && $user->store_id) {
                $model->store_id = $user->store_id;
            }
        });
    }

    /**
     * Get the store that owns this record.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}

