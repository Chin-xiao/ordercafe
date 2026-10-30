<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',      // <--- Add this
        'email',     // <--- Add this
        'password',  // <--- Add this (if not already hidden/guarded)
        'telegram_id',
        'telegram_private_chat_id',
        'telegram_private_chat_activated_at',
        'telegram_last_seen_at',
        'username',
        'first_name',
        'last_name',
        'phone',
        'role',
        'is_active',
        'is_admin',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_admin' => 'boolean',
        'telegram_id' => 'integer',
        'telegram_private_chat_id' => 'integer',
        'telegram_private_chat_activated_at' => 'datetime',
        'telegram_last_seen_at' => 'datetime',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function customerMessages()
    {
        return $this->hasMany(TelegramCustomerMessage::class, 'customer_id');
    }

    public function sentCustomerMessages()
    {
        return $this->hasMany(TelegramCustomerMessage::class, 'admin_id');
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

    public function paymentQrImageUrl(): ?string
    {
        return $this->paymentSetting?->qr_image_url;
    }

    public function paymentSetting(): HasOne
    {
        return $this->hasOne(PaymentSetting::class);
    }

    public function paymentLink(): ?string
    {
        return $this->paymentSetting?->payment_link;
    }
    public function store() {
    return $this->belongsTo(Store::class);
}

}
