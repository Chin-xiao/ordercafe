<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\TelegramSettingsController;
use App\Models\TelegramGroup;
use App\Models\TelegramSetting; // Or your TelegramGroup model depending on how you named it
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $update = $request->all();

        // Check if message exists from Telegram
        if (isset($update['message'])) {
            $chat = $update['message']['chat'];
            $text = $update['message']['text'] ?? '';
            $chatId = $chat['id'];
            $chatType = $chat['type']; // 'group' or 'supergroup'

            // Check if it's a group command starting with /verify
            if (in_array($chatType, ['group', 'supergroup']) && str_starts_with($text, '/verify')) {
                $parts = explode(' ', $text);
                if (isset($parts[1])) {
                    $token = trim($parts[1]);

                    // Find the setting matching this token
                    $setting = TelegramGroup::where('verify_token', $token)->first();
                    $botToken = env('TELEGRAM_BOT_TOKEN');

                    if ($setting) {
                        $setting->chat_id = $chatId;
                        $setting->group_name = $chat['title'] ?? 'Telegram Group';
                        $setting->is_verified = true;
                        $setting->save();

                        // Success response message into the group
                        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                            'chat_id' => $chatId,
                            'text' => "✅ *Group Linked Successfully!*\n\nThis group is now connected to your Cafe Order System dashboard.",
                            'parse_mode' => 'Markdown'
                        ]);
                    } else {
                        // Error response
                        Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                            'chat_id' => $chatId,
                            'text' => "❌ Invalid verification token. Please check your admin settings panel.",
                        ]);
                    }
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
