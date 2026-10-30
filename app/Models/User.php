<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
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

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function customerMessages(): HasMany
    {
        return $this->hasMany(TelegramCustomerMessage::class, 'customer_id');
    }

    public function sentCustomerMessages(): HasMany
    {
        return $this->hasMany(TelegramCustomerMessage::class, 'admin_id');
    }

    public function createdOrderSessions(): HasMany
    {
        return $this->hasMany(OrderSession::class, 'created_by');
    }

    public function closedOrderSessions(): HasMany
    {
        return $this->hasMany(OrderSession::class, 'closed_by');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /**
     * Relationship to the user's dedicated payment settings.
     */
    public function paymentSetting(): HasOne
    {
        return $this->hasOne(PaymentSetting::class);
    }

    /**
     * Helper to retrieve the active payment QR image URL or null.
     */
    public function paymentQrImageUrl(): ?string
    {
        return $this->paymentSetting?->qr_image_url;
    }

    /**
     * Helper to retrieve the active direct payment link or null.
     */
    public function paymentLink(): ?string
    {
        return $this->paymentSetting?->payment_link;
    }
}
