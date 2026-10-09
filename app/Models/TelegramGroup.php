<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramGroup extends Model
{
    protected $fillable = [
        'telegram_chat_id',
        'title',
        'group_name',
        'verify_token',
        'is_verified',
        'is_active',
    ];

    protected $casts = [
        'telegram_chat_id' => 'integer',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
    ];
}
