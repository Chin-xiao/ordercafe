<?php

namespace App\Models;

use App\Models\Traits\BelongsToStore;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id',
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
}
