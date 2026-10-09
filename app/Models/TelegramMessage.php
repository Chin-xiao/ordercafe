<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramMessage extends Model
{
    protected $fillable = [
        'telegram_group_id',
        'order_session_id',
        'message_type',
        'telegram_chat_id',
        'telegram_message_id',
        'status',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'telegram_chat_id' => 'integer',
        'telegram_message_id' => 'integer',
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
