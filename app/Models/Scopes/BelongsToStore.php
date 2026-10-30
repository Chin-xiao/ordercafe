<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * @deprecated Use the App\Models\Traits\BelongsToStore trait instead.
 *
 * This class-based scope is kept for backward compatibility. The trait provides
 * both the global scope AND the auto-stamping creating hook in one package.
 */
class StoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Auth::check()) {
            $user = Auth::user();
            // If the user is a store owner, automatically scope queries to their store_id
            if ($user->role === 'store_owner' && $user->store_id) {
                $builder->where($model->getTable() . '.store_id', $user->store_id);
            }
        }
    }
}
