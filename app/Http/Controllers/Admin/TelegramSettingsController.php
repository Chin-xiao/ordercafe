<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TelegramGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelegramSettingsController extends Controller
{
    public function show()
    {
        $group = TelegramGroup::first();

        if (!$group || empty($group->verify_token)) {
            $attributes = [
                'verify_token' => 'CAFE_' . strtoupper(Str::random(32)),
                'is_verified' => false,
            ];

            if ($group) {
                $group->update($attributes);
            } else {
                $group = TelegramGroup::create($attributes);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'verify_token' => $group->verify_token,
                'setup_command' => '/setup ' . $group->verify_token,
                'is_verified' => $group->is_verified,
                'group_name' => $group->group_name ?: $group->title,
                'chat_id' => $group->telegram_chat_id,
            ]
        ]);
    }

    public function regenerate()
    {
        $group = TelegramGroup::first() ?? new TelegramGroup();
        $group->verify_token = 'CAFE_' . strtoupper(Str::random(32));
        $group->telegram_chat_id = null;
        $group->title = null;
        $group->group_name = null;
        $group->is_verified = false;
        $group->is_active = true;
        $group->save();

        return response()->json([
            'success' => true,
            'message' => 'New verification token generated.',
            'data' => [
                'verify_token' => $group->verify_token,
                'setup_command' => '/setup ' . $group->verify_token,
                'is_verified' => $group->is_verified,
                'group_name' => $group->group_name,
                'chat_id' => $group->chat_id,
            ]
        ]);
    }
}
