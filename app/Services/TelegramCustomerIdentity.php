<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TelegramCustomerIdentity
{
    public function sync(array $telegramUser): User
    {
        $telegramId = $telegramUser['id'] ?? null;
        if (! is_int($telegramId) && (! is_string($telegramId) || ! ctype_digit($telegramId))) {
            throw new \InvalidArgumentException('A verified numeric Telegram user ID is required.');
        }

        $telegramId = (int) $telegramId;
        $customer = User::query()->firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'name' => $this->displayName($telegramUser) ?? 'Telegram user',
                'email' => 'telegram'.$telegramId.'@users.invalid',
                'password' => Hash::make(Str::random(64)),
                'role' => 'user',
                'is_active' => true,
            ]
        );

        $profile = [];
        foreach (['username', 'first_name', 'last_name'] as $field) {
            $value = $telegramUser[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $profile[$field] = trim($value);
            }
        }

        $displayName = $this->displayName([
            'first_name' => $profile['first_name'] ?? $customer->first_name,
            'last_name' => $profile['last_name'] ?? $customer->last_name,
            'username' => $profile['username'] ?? $customer->username,
        ]);
        if ($displayName !== null) {
            $profile['name'] = $displayName;
        }

        $lastSeenThreshold = Carbon::now('UTC')->subMinutes(5);
        if (! $customer->telegram_last_seen_at
            || $customer->telegram_last_seen_at->lessThanOrEqualTo($lastSeenThreshold)) {
            $profile['telegram_last_seen_at'] = Carbon::now('UTC');
        }

        if ($profile !== []) {
            $customer->fill($profile);
            if ($customer->isDirty()) {
                $customer->save();
            }
        }

        return $customer;
    }

    private function displayName(array $telegramUser): ?string
    {
        $name = trim(implode(' ', array_filter([
            is_string($telegramUser['first_name'] ?? null) ? trim($telegramUser['first_name']) : null,
            is_string($telegramUser['last_name'] ?? null) ? trim($telegramUser['last_name']) : null,
        ])));

        if ($name !== '') {
            return $name;
        }

        $username = $telegramUser['username'] ?? null;

        return is_string($username) && trim($username) !== '' ? trim($username) : null;
    }
}
