<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramMessage extends Model
{
    protected $fillable = [
        'telegram_group_id',
        'order_session_id',
        'message_type',
        'telegram_message_id',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function telegramGroup()
    {
        return $this->belongsTo(TelegramGroup::class);
    }

    public function orderSession()
    {
        return $this->belongsTo(OrderSession::class);
    }
}
