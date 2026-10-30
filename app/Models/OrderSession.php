<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth; // <-- Import the Auth facade

class OrderSession extends Model
{
    protected $fillable = [
        'store_id',
        'order_number',
        'title',
        'scheduled_start_at',
        'duration_minutes',
        'announcement_message',
        'started_at',
        'expires_at',
        'closed_at',
        'status',
        'created_by',
        'closed_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'scheduled_start_at' => 'datetime',
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
        'duration_minutes' => 'integer',
    ];

    // Automatically scope queries and assignments for store owners
    protected static function booted()
    {
        // 1. Automatically filter queries by store_id if logged in as a store owner
        static::addGlobalScope('store', function (Builder $builder) {
            if (Auth::check()) {
                $user = Auth::user();
                if ($user->role === 'store_owner' && $user->store_id) {
                    $builder->where('order_sessions.store_id', $user->store_id);
                }
            }
        });

        // 2. Automatically assign store_id when a store owner creates a session
        static::creating(function ($model) {
            if (Auth::check()) {
                $user = Auth::user();
                if ($user->role === 'store_owner' && empty($model->store_id)) {
                    $model->store_id = $user->store_id;
                }
            }
        });
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function telegramMessages()
    {
        return $this->hasMany(TelegramMessage::class);
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }
}
