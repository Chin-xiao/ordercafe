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
        // Find the first group record, or create one if it doesn't exist yet
        $group = TelegramGroup::first();

        if (!$group || empty($group->verify_token)) {
            $group = TelegramGroup::updateOrCreate(
                ['id' => $group?->id],
                [
                    'verify_token' => 'CAFE_' . strtoupper(Str::random(8)),
                    'is_verified' => false,
                ]
            );
        }

        return response()->json([
            'success' => true,
            'data' => [
                'verify_token' => $group->verify_token,
                'setup_command' => '/setup ' . $group->verify_token,
                'is_verified' => $group->is_verified,
                'group_name' => $group->group_name,
                'chat_id' => $group->chat_id,
            ]
        ]);
    }

    public function regenerate()
    {
        $group = TelegramGroup::first() ?? new TelegramGroup();
        $group->verify_token = 'CAFE_' . strtoupper(Str::random(8));
        $group->chat_id = null;
        $group->group_name = null;
        $group->is_verified = false;
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
