<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderSession extends Model
{
    protected $fillable = [
        'order_number',
        'title',
        'started_at',
        'expires_at',
        'closed_at',
        'status',
        'created_by',
        'closed_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'expires_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

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
}
