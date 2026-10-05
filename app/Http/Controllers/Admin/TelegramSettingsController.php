<?php

namespace App\Http\Controllers;

use App\Models\TelegramGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $update = $request->all();

        // Check if the message exists and contains text
        if (isset($update['message']['text'])) {
            $text = $update['message']['text'];
            $chatId = $update['message']['chat']['id'];
            $groupName = $update['message']['chat']['title'] ?? 'Telegram Group';

            // Check if the message starts with /verify
            if (str_starts_with($text, '/verify')) {
                // Extract everything after /verify (e.g. CAFE_9X2K4L1M)
                $token = trim(str_replace('/verify', '', $text));

                // Find the group record matching this unique random token from the admin panel
                $group = TelegramGroup::where('verify_token', $token)->first();

                if ($group) {
                    // Update group info and mark as verified
                    $group->chat_id = $chatId;
                    $group->group_name = $groupName;
                    $group->is_verified = true;
                    $group->save();

                    // Automatically reply back to the Telegram group
                    $botToken = env('TELEGRAM_BOT_TOKEN');
                    Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => "✅ Group successfully verified and linked to the Cafe Ordering system!",
                    ]);
                } else {
                    // Optional: Notify if the token is invalid or expired
                    $botToken = env('TELEGRAM_BOT_TOKEN');
                    Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => "❌ Invalid verification token. Please check your admin panel for the correct command.",
                    ]);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
