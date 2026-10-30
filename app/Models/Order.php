<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Auth;
class Order extends Model
{
    protected static function booted(): void
    {
        // 1. Automatically filter queries by store_id if logged in as a store owner
        static::addGlobalScope('store', function (Builder $builder) {
            if (Auth::check()) {
                $user = Auth::user();
                if ($user->role === 'store_owner' && $user->store_id) {
                    $builder->where('orders.store_id', $user->store_id);
                }
            }
        });

        // 2. Automatically assign store_id when creating an order
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
        'order_number',
        'order_session_id',
        'user_id',
        'total_amount',
        'status',
        'submitted_at',
        'cancelled_at',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'submitted_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(OrderSession::class, 'order_session_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
