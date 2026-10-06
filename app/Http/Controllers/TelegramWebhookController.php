<?php

namespace App\Http\Controllers;

use App\Models\TelegramGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log; // <--- Make sure this is imported

class TelegramWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $update = $request->all();

        // Log the raw incoming request from Telegram to your storage/logs/laravel.log
        Log::info('Telegram Webhook Payload:', $update);

        if (isset($update['message']['text'])) {
            $text = $update['message']['text'];
            $chatId = $update['message']['chat']['id'];
            $groupName = $update['message']['chat']['title'] ?? 'Telegram Group';

            // Clean text to handle /setup@ahseven_bot format as well
            $cleanText = preg_replace('/@\w+/', '', $text); // Removes @ahseven_bot if present

            if (str_starts_with(trim($cleanText), '/setup')) {
                $token = trim(str_replace('/setup', '', $cleanText));

                $group = TelegramGroup::where('verify_token', $token)->first();
                $botToken = env('TELEGRAM_BOT_TOKEN');

                if ($group) {
                    $group->chat_id = $chatId;
                    $group->group_name = $groupName;
                    $group->is_verified = true;
                    $group->save();

                    Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => "✅ Group successfully verified and linked to the Cafe Ordering system!",
                    ]);
                } else {
                    Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                        'chat_id' => $chatId,
                        'text' => "❌ Invalid verification token: {$token}",
                    ]);
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
