<?php

namespace App\Services;

use App\Models\OrderSession;
use App\Models\TelegramGroup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $botToken;
    protected string $apiUrl;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}";
    }

    /**
     * Send order session "OPEN" notification with a Web App button.
     */
    public function sendOrderStartedNotification(OrderSession $session): void
    {
        $groups = TelegramGroup::where('is_active', true)->get();

        $miniAppUrl = config('services.telegram.mini_app_url'); // e.g. https://t.co/YourBotName/app

        $message = "🍔 *CAFE ORDER IS OPEN!*\n\n";
        $message .= "📋 *{$session->title}*\n";

        if ($session->expires_at) {
            $message .= "⏰ *Order before:* " . $session->expires_at->format('h:i A') . "\n";
        }

        $message .= "\nPlease place your order before the deadline! ❤️";

        // Telegram Inline Keyboard with WebApp button
        $replyMarkup = [
            'inline_keyboard' => [
                [
                    [
                        'text' => '🛒 ORDER NOW',
                        'web_app' => ['url' => $miniAppUrl]
                    ]
                ]
            ]
        ];

        foreach ($groups as $group) {
            $this->sendMessage($group->telegram_chat_id, $message, $replyMarkup);
        }
    }

    /**
     * Send order session summary when closed by admin.
     */
    public function sendOrderSummaryNotification(array $summaryData): void
    {
        $session = $summaryData['session'];
        $groups = TelegramGroup::where('is_active', true)->get();

        $message = "🔴 *CAFE ORDER CLOSED*\n\n";
        $message .= "📋 *{$session->title}*\n\n";
        $message .= "👥 *Customers:* {$summaryData['total_customers']}\n";
        $message .= "🛒 *Orders:* {$summaryData['total_orders']}\n";
        $message .= "💰 *Total Revenue:* \${$summaryData['total_revenue']}\n\n";

        $message .= "-------------------------\n";
        $message .= "📦 *PRODUCT SUMMARY*\n\n";

        foreach ($summaryData['product_summary'] as $item) {
            $message .= "• {$item['product_name']} × {$item['total_quantity']} (\${$item['subtotal']})\n";
        }

        $message .= "\n-------------------------\n";
        $message .= "Thank you everyone! ❤️";

        foreach ($groups as $group) {
            $this->sendMessage($group->telegram_chat_id, $message);
        }
    }

    /**
     * Low-level helper to hit Telegram sendMessage endpoint.
     */
    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): void
    {
        if (empty($this->botToken)) {
            Log::warning('Telegram bot token is missing. Message not sent.');
            return;
        }

        try {
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ];

            if ($replyMarkup) {
                $payload['reply_markup'] = $replyMarkup;
            }

            $response = Http::post("{$this->apiUrl}/sendMessage", $payload);

            if ($response->failed()) {
                Log::error('Failed to send Telegram message: ' . $response->body());
            }
        } catch (\Exception $e) {
            Log::error('Exception while sending Telegram message: ' . $e->getMessage());
        }
    }
}
