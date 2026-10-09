<?php

namespace App\Services;

use App\Models\OrderSession;
use App\Models\TelegramGroup;
use App\Models\TelegramMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected ?string $botToken;

    protected string $apiUrl;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token');
        $this->apiUrl = $this->botToken ? "https://api.telegram.org/bot{$this->botToken}" : '';
    }

    public function sendOrderStartedNotification(OrderSession $session): array
    {
        try {
            $appUrl = $this->sessionMiniAppUrl($session);
            $groups = $this->verifiedGroups();
        } catch (TelegramDeliveryException $exception) {
            throw new TelegramDeliveryException($exception->getMessage(), [
                'session' => $session,
                'notifications' => [],
            ]);
        }

        $timezone = config('app.business_timezone');
        $message = "🍽️ CAFE ORDER IS NOW OPEN!\n\n";
        $message .= "📢 You can order now!\n\n";
        $message .= "📝 Order Session: {$session->title}\n\n";
        $message .= '⏰ Order Deadline: '.$session->expires_at
            ->copy()
            ->setTimezone($timezone)
            ->format('l, F j, Y g:i A T')." ({$timezone})\n\n";
        $message .= ($session->announcement_message
            ?: 'Please open the Mini App, choose your favorite drinks and food, and submit your order before the deadline.')
            ."\n\n";
        $message .= "👇 Click the button below to order now!\n\n";
        $message .= 'Thank you!';

        return $this->sendToGroups(
            $groups,
            $session,
            'session_opened',
            $message,
            [
                'inline_keyboard' => [[
                    [
                        'text' => '🛒 ORDER NOW',
                        'url' => $appUrl,
                    ],
                ]],
            ]
        );
    }

    public function sendOrderSummaryNotification(array $summaryData): array
    {
        /** @var OrderSession $session */
        $session = $summaryData['session'];
        try {
            $groups = $this->verifiedGroups();
        } catch (TelegramDeliveryException $exception) {
            throw new TelegramDeliveryException($exception->getMessage(), [
                'summary' => $summaryData,
                'notifications' => [],
            ]);
        }

        $message = "ORDER SESSION CLOSED\n\n";
        $message .= "Session: {$session->title}\n\n";
        $message .= "Customers ordered: {$summaryData['total_customers']}\n";
        $message .= "Total orders: {$summaryData['total_orders']}\n\n";
        $message .= "Order summary:\n";

        foreach ($summaryData['product_summary'] as $item) {
            $message .= "- {$item['product_name']} × {$item['total_quantity']}\n";
        }

        $message .= "\nTotal amount: \${$summaryData['total_revenue']}\n\nThank you for ordering!";

        return $this->sendToGroups($groups, $session, 'session_closed', $message);
    }

    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): int
    {
        if (! $this->botToken) {
            Log::warning('Telegram notification failed: TELEGRAM_BOT_TOKEN is not configured.');
            throw new TelegramDeliveryException('The Telegram bot token is not configured.');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        try {
            $response = Http::timeout(10)->post("{$this->apiUrl}/sendMessage", $payload);
        } catch (ConnectionException) {
            throw new TelegramDeliveryException('The Telegram API could not be reached.');
        }

        $telegramMessageId = $response->json('result.message_id');
        if ($response->failed() || $response->json('ok') !== true || ! is_numeric($telegramMessageId)) {
            $errorCode = $response->json('error_code');
            $description = $response->json('description');
            $details = is_string($description) && $description !== ''
                ? ": {$description}"
                : '';
            $code = is_numeric($errorCode) ? ", Telegram error {$errorCode}" : '';

            throw new TelegramDeliveryException(
                "Telegram rejected the message (HTTP {$response->status()}{$code}){$details}"
            );
        }

        return (int) $telegramMessageId;
    }

    private function verifiedGroups()
    {
        if (! $this->botToken) {
            throw new TelegramDeliveryException('The Telegram bot token is not configured.');
        }

        $groups = TelegramGroup::query()
            ->where('is_active', true)
            ->where('is_verified', true)
            ->whereNotNull('telegram_chat_id')
            ->get();

        if ($groups->isEmpty()) {
            Log::warning('Telegram notification skipped: no active verified groups are configured.');
            throw new TelegramDeliveryException('No active verified Telegram group is configured.');
        }

        return $groups;
    }

    private function sessionMiniAppUrl(OrderSession $session): string
    {
        $configuredUrl = config('services.telegram.mini_app_url');
        $parts = is_string($configuredUrl) ? parse_url($configuredUrl) : false;
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $path = trim(is_array($parts) ? ($parts['path'] ?? '') : '', '/');

        if (! is_string($configuredUrl)
            || ! filter_var($configuredUrl, FILTER_VALIDATE_URL)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! in_array($host, ['t.me', 'telegram.me'], true)
            || count(explode('/', $path)) < 2) {
            Log::warning('Telegram Mini App link configuration is invalid.', [
                'session_id' => $session->id,
            ]);
            throw new TelegramDeliveryException(
                'TELEGRAM_MINI_APP_URL must be the HTTPS Telegram Mini App deep link configured for this bot.'
            );
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $query['startapp'] = (string) $session->id;

        return ($parts['scheme'].'://'.$parts['host']
            .($parts['port'] ?? null ? ':'.$parts['port'] : '')
            .($parts['path'] ?? ''))
            .'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function sendToGroups(
        iterable $groups,
        OrderSession $session,
        string $messageType,
        string $text,
        ?array $replyMarkup = null
    ): array {
        $results = [];

        foreach ($groups as $group) {
            $alreadySent = TelegramMessage::query()
                ->where('telegram_group_id', $group->id)
                ->where('order_session_id', $session->id)
                ->where('message_type', $messageType)
                ->where('status', 'sent')
                ->exists();

            if ($alreadySent) {
                $results[] = [
                    'telegram_group_id' => $group->id,
                    'status' => 'already_sent',
                ];

                continue;
            }

            $delivery = TelegramMessage::create([
                'telegram_group_id' => $group->id,
                'order_session_id' => $session->id,
                'message_type' => $messageType,
                'telegram_chat_id' => $group->telegram_chat_id,
                'status' => 'pending',
            ]);

            try {
                $messageId = $this->sendMessage($group->telegram_chat_id, $text, $replyMarkup);
                $delivery->update([
                    'telegram_message_id' => $messageId,
                    'status' => 'sent',
                    'sent_at' => Carbon::now('UTC'),
                ]);
                $results[] = [
                    'telegram_group_id' => $group->id,
                    'status' => 'sent',
                    'telegram_message_id' => $messageId,
                ];
            } catch (TelegramDeliveryException $exception) {
                $delivery->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                ]);
                Log::warning('Telegram notification delivery failed.', [
                    'session_id' => $session->id,
                    'telegram_group_id' => $group->id,
                    'telegram_chat_id' => $group->telegram_chat_id,
                    'message_type' => $messageType,
                    'error' => $exception->getMessage(),
                ]);
                $results[] = [
                    'telegram_group_id' => $group->id,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return $results;
    }
}
