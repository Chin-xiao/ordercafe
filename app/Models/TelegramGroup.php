<?php

namespace App\Models;

use App\Models\Traits\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

class TelegramGroup extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id',
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
