<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramCustomerMessage extends Model
{
    protected $fillable = [
        'customer_id',
        'admin_id',
        'message_text',
        'idempotency_key',
        'status',
        'telegram_message_id',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'telegram_message_id' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
