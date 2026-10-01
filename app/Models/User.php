<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;
    protected $fillable = [
        'telegram_id',
        'username',
        'first_name',
        'last_name',
        'phone',
        'role',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'telegram_id' => 'integer',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function createdOrderSessions()
    {
        return $this->hasMany(OrderSession::class, 'created_by');
    }

    public function closedOrderSessions()
    {
        return $this->hasMany(OrderSession::class, 'closed_by');
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
