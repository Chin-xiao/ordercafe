<?php

namespace App\Services;

use App\Models\OrderSession;
use App\Models\TelegramGroup;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramService
{
    protected ?string $botToken;
    protected string $apiUrl;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->apiUrl = $this->botToken ? "https://api.telegram.org/bot{$this->botToken}" : '';
    }

    /**
     * Send order session "OPEN" notification with a Web App button.
     */
    public function sendOrderStartedNotification(OrderSession $session): void
    {
        $groups = $this->verifiedGroups();

        $miniAppUrl = config('services.telegram.mini_app_url');
        if (!is_string($miniAppUrl)
            || !filter_var($miniAppUrl, FILTER_VALIDATE_URL)
            || parse_url($miniAppUrl, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('A valid HTTPS Telegram Mini App URL is not configured.');
        }

        $message = "🍔 *CAFE ORDER IS OPEN!*\n\n";
        $message .= "📋 *{$session->title}*\n";

        if ($session->expires_at) {
            $message .= "⏰ *Order before:* " . $session->expires_at->format('h:i A') . "\n";
        }

        $message .= "\nPlease place your order before the deadline! ❤️";

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
        $groups = $this->verifiedGroups();

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
        if (!$this->botToken) {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }

        try {
            $response = Http::timeout(10)->post("{$this->apiUrl}/sendMessage", $payload);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not deliver the Telegram notification.');
        }

        if ($response->failed() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram rejected the notification request.');
        }
    }

    private function verifiedGroups()
    {
        if (!$this->botToken) {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $groups = TelegramGroup::where('is_active', true)
            ->where('is_verified', true)
            ->whereNotNull('telegram_chat_id')
            ->get();

        if ($groups->isEmpty()) {
            throw new RuntimeException('No verified Telegram group is configured.');
        }

        return $groups;
    }
}
