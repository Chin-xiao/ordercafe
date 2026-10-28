<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
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
        'payment_qr_path',
        'is_active',
        'is_admin',
        'payment_link',
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
        if (! $this->payment_qr_path) {
            return null;
        }

        // If it's already an absolute URL (Cloudinary / external CDN), return directly
        if (str_starts_with($this->payment_qr_path, 'http://') || str_starts_with($this->payment_qr_path, 'https://')) {
            return $this->payment_qr_path;
        }

        // Fallback for older local storage files
        return asset('storage/' . $this->payment_qr_path);
    }
    public function paymentSetting(): HasOne
{
    return $this->hasOne(PaymentSetting::class);
}


public function paymentLink(): ?string
{
    return $this->paymentSetting?->payment_link;
}
}
