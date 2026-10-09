<?php

namespace App\Http\Controllers;

use App\Models\TelegramGroup;
use App\Services\TelegramService;
use Illuminate\Http\Request;

class TelegramWebhookController extends Controller
{
    public function __construct(private TelegramService $telegramService)
    {
    }

    public function handle(Request $request)
    {
        $update = $request->all();

        if (isset($update['message']['text'])) {
            $text = $update['message']['text'];
            $chatId = $update['message']['chat']['id'];
            $groupName = $update['message']['chat']['title'] ?? 'Telegram Group';

            // Clean text to handle /setup@ahseven_bot format as well
            $cleanText = preg_replace('/@\w+/', '', $text); // Removes @ahseven_bot if present

            if (str_starts_with(trim($cleanText), '/setup')) {
                $token = trim(str_replace('/setup', '', $cleanText));

                $group = TelegramGroup::where('verify_token', $token)->first();
                if ($group) {
                    $group->telegram_chat_id = $chatId;
                    $group->title = $groupName;
                    $group->group_name = $groupName;
                    $group->is_verified = true;
                    $group->save();

                    $this->telegramService->sendMessage(
                        $chatId,
                        '✅ Group successfully verified and linked to the Cafe Ordering system!'
                    );
                } else {
                    $this->telegramService->sendMessage(
                        $chatId,
                        "❌ Invalid verification token: {$token}"
                    );
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
