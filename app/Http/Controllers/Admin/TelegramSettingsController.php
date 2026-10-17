<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TelegramGroup;
use Illuminate\Support\Str;

class TelegramSettingsController extends Controller
{
    public function show()
    {
        $group = TelegramGroup::query()
            ->where('is_verified', false)
            ->whereNull('telegram_chat_id')
            ->whereNotNull('verify_token')
            ->latest('id')
            ->first();

        if (! $group) {
            $group = $this->createPendingGroup();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'verify_token' => $group->verify_token,
                'setup_command' => '/setup '.$group->verify_token,
                'is_verified' => $group->is_verified,
                'group_name' => $group->group_name ?: $group->title,
                'chat_id' => $group->telegram_chat_id,
            ],
        ]);
    }

    public function regenerate()
    {
        $group = $this->createPendingGroup();

        return response()->json([
            'success' => true,
            'message' => 'New verification token generated.',
            'data' => [
                'verify_token' => $group->verify_token,
                'setup_command' => '/setup '.$group->verify_token,
                'is_verified' => $group->is_verified,
                'group_name' => $group->group_name,
                'chat_id' => $group->telegram_chat_id,
            ],
        ]);
    }

    private function createPendingGroup(): TelegramGroup
    {
        return TelegramGroup::create([
            'verify_token' => 'CAFE_'.strtoupper(Str::random(32)),
            'is_verified' => false,
            'is_active' => true,
        ]);
    }
}
