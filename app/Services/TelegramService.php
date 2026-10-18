<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderSession;
use App\Models\TelegramGroup;
use App\Models\TelegramMessage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            $groups = $this->verifiedGroups($session);
        } catch (TelegramDeliveryException $exception) {
            throw new TelegramDeliveryException($exception->getMessage(), [
                'session' => $session,
                'notifications' => [],
            ]);
        }

        try {
            $appUrl = $this->sessionMiniAppUrl($session);
        } catch (TelegramDeliveryException $exception) {
            $notifications = $this->recordFailedDeliveries(
                $groups,
                $session,
                'session_opened',
                $exception->getMessage()
            );

            throw new TelegramDeliveryException($exception->getMessage(), [
                'session' => $session,
                'notifications' => $notifications,
            ]);
        }

        return $this->sendToGroups(
            $groups,
            $session,
            'session_opened',
            $this->orderStartedMessage($session),
            [
                'inline_keyboard' => [[
                    [
                        'text' => '🛒 Order Now',
                        'url' => $appUrl,
                    ],
                ]],
            ],
            'HTML'
        );
    }

    public function retryOrderStartedNotification(OrderSession $session): array
    {
        try {
            $groups = $this->verifiedGroups($session)->filter(
                fn (TelegramGroup $group) => TelegramMessage::query()
                    ->where('telegram_group_id', $group->id)
                    ->where('order_session_id', $session->id)
                    ->where('message_type', 'session_opened')
                    ->where('status', 'failed')
                    ->exists()
            );
        } catch (TelegramDeliveryException $exception) {
            throw new TelegramDeliveryException($exception->getMessage(), [
                'session' => $session,
                'notifications' => [],
            ]);
        }

        $appUrl = $this->sessionMiniAppUrl($session);

        if ($groups->isEmpty()) {
            return [];
        }

        return $this->sendToGroups(
            $groups,
            $session,
            'session_opened',
            $this->orderStartedMessage($session),
            [
                'inline_keyboard' => [[
                    [
                        'text' => '🛒 Order Now',
                        'url' => $appUrl,
                    ],
                ]],
            ],
            'HTML',
            true
        );
    }

    public function sendOrderSummaryNotification(array $summaryData): array
    {
        /** @var OrderSession $session */
        $session = $summaryData['session'];
        try {
            $groups = $this->verifiedGroups($session);
        } catch (TelegramDeliveryException $exception) {
            throw new TelegramDeliveryException($exception->getMessage(), [
                'summary' => $summaryData,
                'notifications' => [],
            ]);
        }

        $closingStatus = $summaryData['closing_status'] ?? $session->status;
        $lines = [
            '<b>Order Summary</b>',
            'Session: '.htmlspecialchars($session->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'Status: '.htmlspecialchars((string) $closingStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            '',
        ];

        if ((int) $summaryData['total_orders'] === 0) {
            $lines[] = 'No orders were placed in this session.';
        } elseif ($summaryData['product_summary'] === []) {
            $lines[] = 'No drink items were recorded for this session.';
        } else {
            foreach ($summaryData['product_summary'] as $item) {
                $productName = htmlspecialchars($item['product_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $sugar = $item['sugar_percent'] === null ? '' : " {$item['sugar_percent']}%";
                $quantity = (int) $item['total_quantity'];
                $unitPrice = $this->formatCurrency($item['unit_price']);
                $lineTotal = $this->formatCurrency($item['subtotal']);
                $description = "- {$productName}{$sugar} x{$quantity}";

                if ($quantity > 1) {
                    $description .= " Unit price: {$unitPrice}៛; Line total: {$lineTotal}៛";
                } else {
                    $description .= " Price: {$lineTotal}៛";
                }

                $lines[] = $description;
            }
        }

        $lines[] = '';
        $lines[] = '<b>Total Price: '.$this->formatCurrency($summaryData['total_revenue']).'៛</b>';

        $results = [];
        foreach ($this->splitTelegramMessageLines($lines) as $index => $message) {
            $messageType = $index === 0 ? 'session_closed' : 'session_closed_part_'.($index + 1);
            $results = [
                ...$results,
                ...$this->sendToGroups($groups, $session, $messageType, $message, null, 'HTML'),
            ];
        }

        return $results;
    }

    public function sendOrderPlacedNotification(Order $order): array
    {
        $order->loadMissing(['items', 'session', 'user']);
        $groups = $this->verifiedGroups($order->session);

        return $this->sendToGroups(
            $groups,
            $order->session,
            'order_placed_'.$order->id,
            $this->orderPlacedMessage($order),
            null,
            'HTML'
        );
    }

    public function sendMessage(
        int|string $chatId,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null
    ): int
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
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        $startedAt = hrtime(true);
        try {
            $response = Http::timeout(10)->post("{$this->apiUrl}/sendMessage", $payload);
        } catch (ConnectionException) {
            Log::warning('Telegram API request failed to connect.', [
                'operation' => 'sendMessage',
                'duration_ms' => $this->durationMs($startedAt),
            ]);
            throw new TelegramDeliveryException('The Telegram API could not be reached.');
        }

        $duration = $this->durationMs($startedAt);
        $telegramMessageId = $response->json('result.message_id');
        if ($response->failed() || $response->json('ok') !== true || ! is_numeric($telegramMessageId)) {
            $errorCode = $response->json('error_code');
            $description = $response->json('description');
            $details = is_string($description) && $description !== ''
                ? ": {$description}"
                : '';
            $code = is_numeric($errorCode) ? ", Telegram error {$errorCode}" : '';

            Log::warning('Telegram API rejected a message request.', [
                'operation' => 'sendMessage',
                'http_status' => $response->status(),
                'telegram_error_code' => is_numeric($errorCode) ? (int) $errorCode : null,
                'duration_ms' => $duration,
            ]);

            throw new TelegramDeliveryException(
                "Telegram rejected the message (HTTP {$response->status()}{$code}){$details}",
                [],
                is_numeric($errorCode) ? (int) $errorCode : null,
                is_string($description) && $description !== '' ? $description : null,
                is_numeric($response->json('parameters.retry_after'))
                    ? (int) $response->json('parameters.retry_after')
                    : null
            );
        }

        Log::info('Telegram API request completed.', [
            'operation' => 'sendMessage',
            'http_status' => $response->status(),
            'duration_ms' => $duration,
        ]);

        return (int) $telegramMessageId;
    }

    public function getWebhookDiagnostics(): array
    {
        if (! $this->botToken) {
            throw new TelegramWebhookDiagnosticsException();
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(10)
                ->get("{$this->apiUrl}/getWebhookInfo");
        } catch (ConnectionException) {
            throw new TelegramWebhookDiagnosticsException();
        }

        $result = $response->json('result');
        $pendingUpdateCount = is_array($result) ? ($result['pending_update_count'] ?? null) : null;
        $lastErrorDate = is_array($result) ? ($result['last_error_date'] ?? null) : null;
        $lastErrorMessage = is_array($result) ? ($result['last_error_message'] ?? null) : null;

        if ($response->failed()
            || $response->json('ok') !== true
            || ! is_array($result)
            || ! is_int($pendingUpdateCount)
            || $pendingUpdateCount < 0
            || ($lastErrorDate !== null && (! is_int($lastErrorDate) || $lastErrorDate < 0))
            || ($lastErrorMessage !== null && ! is_string($lastErrorMessage))) {
            throw new TelegramWebhookDiagnosticsException();
        }

        return [
            'webhook_url_configured' => is_string($result['url'] ?? null) && trim($result['url']) !== '',
            'pending_update_count' => $pendingUpdateCount,
            'last_error_date' => $lastErrorDate === null
                ? null
                : Carbon::createFromTimestampUTC($lastErrorDate)->toIso8601String(),
            'last_error_message' => is_string($lastErrorMessage)
                ? $this->sanitizeWebhookDiagnosticMessage($lastErrorMessage)
                : null,
        ];
    }

    private function sanitizeWebhookDiagnosticMessage(string $message): ?string
    {
        $sensitiveValues = array_filter([
            $this->botToken,
            config('services.telegram.webhook_secret'),
        ], fn ($value) => is_string($value) && $value !== '');

        $message = str_replace($sensitiveValues, '[REDACTED]', $message);
        $message = preg_replace('~https?://\S+~i', '[URL REDACTED]', $message) ?? '[REDACTED]';
        $message = preg_replace(
            '/\b(?:\d{8,}:[A-Za-z0-9_-]{20,}|CAFE_[A-Z0-9]{32})\b/',
            '[REDACTED]',
            $message
        ) ?? '[REDACTED]';
        $message = substr($message, 0, 1000);

        return trim($message) === '' ? null : $message;
    }

    private function verifiedGroups(?OrderSession $session = null)
    {
        if (! $this->botToken) {
            Log::warning('Telegram notification skipped: TELEGRAM_BOT_TOKEN is not configured.', [
                'session_id' => $session?->id,
            ]);
            throw new TelegramDeliveryException('The Telegram bot token is not configured.');
        }

        $groups = TelegramGroup::query()
            ->where('is_active', true)
            ->where('is_verified', true)
            ->whereNotNull('telegram_chat_id')
            ->orderByDesc('id')
            ->limit(1)
            ->get();

        if ($groups->isEmpty()) {
            Log::warning('Telegram notification skipped: no active verified groups are configured.', [
                'session_id' => $session?->id,
            ]);
            throw new TelegramDeliveryException('No active verified Telegram group is configured.');
        }

        return $groups;
    }

    private function recordFailedDeliveries(
        iterable $groups,
        OrderSession $session,
        string $messageType,
        string $error
    ): array {
        $results = [];

        foreach ($groups as $group) {
            $delivery = TelegramMessage::query()
                ->where('telegram_group_id', $group->id)
                ->where('order_session_id', $session->id)
                ->where('message_type', $messageType)
                ->where('status', 'failed')
                ->first();

            if ($delivery) {
                $delivery->update([
                    'telegram_chat_id' => $group->telegram_chat_id,
                    'error_message' => $error,
                ]);
            } else {
                $delivery = TelegramMessage::create([
                    'telegram_group_id' => $group->id,
                    'order_session_id' => $session->id,
                    'message_type' => $messageType,
                    'idempotency_key' => $this->deliveryKey($group, $session, $messageType),
                    'telegram_chat_id' => $group->telegram_chat_id,
                    'status' => 'failed',
                    'error_message' => $error,
                ]);
            }

            Log::warning('Telegram notification could not be prepared for delivery.', [
                'session_id' => $session->id,
                'telegram_group_id' => $group->id,
                'telegram_chat_id' => $group->telegram_chat_id,
                'message_type' => $messageType,
                'error' => $error,
            ]);

            $results[] = [
                'telegram_group_id' => $group->id,
                'status' => 'failed',
                'error' => $error,
            ];
        }

        return $results;
    }

    private function sessionMiniAppUrl(OrderSession $session): string
    {
        $configuredUrl = config('services.telegram.mini_app_url');
        $parts = is_string($configuredUrl) ? parse_url($configuredUrl) : false;
        $host = is_array($parts) ? strtolower($parts['host'] ?? '') : '';
        $path = trim(is_array($parts) ? ($parts['path'] ?? '') : '', '/');
        $pathSegments = $path === '' ? [] : explode('/', $path);
        $validBotUsername = isset($pathSegments[0])
            && preg_match('/^[A-Za-z0-9_]{5,32}$/', $pathSegments[0]);
        $validMiniAppPath = count($pathSegments) === 1
            || (count($pathSegments) === 2
                && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $pathSegments[1]));

        if (! is_string($configuredUrl)
            || ! filter_var($configuredUrl, FILTER_VALIDATE_URL)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! in_array($host, ['t.me', 'telegram.me'], true)
            || ! $validBotUsername
            || ! $validMiniAppPath) {
            Log::warning('Telegram Mini App link configuration is invalid.', [
                'session_id' => $session->id,
            ]);
            throw new TelegramDeliveryException(
                'TELEGRAM_MINI_APP_URL must be the HTTPS Telegram Mini App deep link configured for this bot (main app or named app link).'
            );
        }

        $bot = $this->getBotDetails($session);
        if (($bot['username'] ?? null) !== $pathSegments[0]) {
            throw new TelegramDeliveryException(
                'TELEGRAM_MINI_APP_URL bot username does not match the configured Telegram bot.'
            );
        }
        if (count($pathSegments) === 1 && ($bot['has_main_web_app'] ?? false) !== true) {
            throw new TelegramDeliveryException(
                'The Telegram bot has no configured Main Mini App. Configure it in BotFather before using the Main Mini App group link.'
            );
        }

        $query = [];
        parse_str($parts['query'] ?? '', $query);
        $query['startapp'] = 'order_'.$session->id;

        return ($parts['scheme'].'://'.$parts['host']
            .($parts['port'] ?? null ? ':'.$parts['port'] : '')
            .($parts['path'] ?? ''))
            .'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            .(isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
    }

    private function getBotDetails(OrderSession $session): array
    {
        if (! $this->botToken) {
            throw new TelegramDeliveryException('The Telegram bot token is not configured.');
        }

        return Cache::store('file')->remember(
            'telegram:bot:details:'.substr(hash('sha256', $this->botToken), 0, 16),
            Carbon::now()->addMinutes(10),
            function () use ($session): array {
                $startedAt = hrtime(true);
                try {
                    $response = Http::timeout(10)->get("{$this->apiUrl}/getMe");
                } catch (ConnectionException) {
                    Log::warning('Telegram bot verification request could not connect.', [
                        'session_id' => $session->id,
                        'operation' => 'getMe',
                        'duration_ms' => $this->durationMs($startedAt),
                    ]);
                    throw new TelegramDeliveryException('The Telegram API could not be reached to verify the Mini App configuration.');
                }

                $duration = $this->durationMs($startedAt);
                $bot = $response->json('result');
                if ($response->failed() || $response->json('ok') !== true || ! is_array($bot)) {
                    Log::warning('Telegram bot configuration could not be verified.', [
                        'session_id' => $session->id,
                        'operation' => 'getMe',
                        'http_status' => $response->status(),
                        'duration_ms' => $duration,
                    ]);
                    throw new TelegramDeliveryException('Telegram rejected the request to verify the bot Mini App configuration.');
                }

                Log::info('Telegram API request completed.', [
                    'operation' => 'getMe',
                    'http_status' => $response->status(),
                    'duration_ms' => $duration,
                ]);

                return $bot;
            }
        );
    }

    private function orderStartedMessage(OrderSession $session): string
    {
        return "តោះមេៗអាច Order Drink អីឡូវ​បាន\n\nPlease Click <b>Order Now</b>";
    }

    private function orderPlacedMessage(Order $order): string
    {
        $customerName = htmlspecialchars(
            $order->user?->name ?? 'Telegram customer',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $lines = [
            '<b>New order '.$this->escapeTelegramText($order->order_number).'</b>',
            'Customer: '.$customerName,
            'Items:',
        ];

        foreach ($order->items as $item) {
            $itemName = $this->escapeTelegramText($item->product_name);
            $sugar = $item->sugar_percent === null ? '' : " ({$item->sugar_percent}% sugar)";
            $lines[] = "- {$itemName} x{$item->quantity}{$sugar}";
        }

        $lines[] = '<b>Total: '.$this->formatCurrency($order->total_amount).'៛</b>';

        return implode("\n", $lines);
    }

    private function escapeTelegramText(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sendToGroups(
        iterable $groups,
        OrderSession $session,
        string $messageType,
        string $text,
        ?array $replyMarkup = null,
        ?string $parseMode = null,
        bool $retryFailed = false
    ): array {
        $results = [];

        foreach ($groups as $group) {
            $claim = $this->claimDelivery($group, $session, $messageType, $retryFailed);
            if ($claim['status'] !== 'claimed') {
                $results[] = [
                    'telegram_group_id' => $group->id,
                    'status' => $claim['status'],
                    ...($claim['error'] ? ['error' => $claim['error']] : []),
                ];

                continue;
            }

            $delivery = $claim['delivery'];

            try {
                $messageId = $this->sendMessage($group->telegram_chat_id, $text, $replyMarkup, $parseMode);
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

    private function claimDelivery(
        TelegramGroup $group,
        OrderSession $session,
        string $messageType,
        bool $retryFailed
    ): array {
        return DB::transaction(function () use ($group, $session, $messageType, $retryFailed): array {
            $key = $this->deliveryKey($group, $session, $messageType);
            $newDelivery = false;
            $delivery = TelegramMessage::query()
                ->where('idempotency_key', $key)
                ->lockForUpdate()
                ->first();

            if (! $delivery) {
                $delivery = TelegramMessage::query()
                    ->where('telegram_group_id', $group->id)
                    ->where('order_session_id', $session->id)
                    ->where('message_type', $messageType)
                    ->where('status', 'sent')
                    ->lockForUpdate()
                    ->first();

                if (! $delivery) {
                    $delivery = TelegramMessage::query()
                        ->where('telegram_group_id', $group->id)
                        ->where('order_session_id', $session->id)
                        ->where('message_type', $messageType)
                        ->where('status', 'pending')
                        ->lockForUpdate()
                        ->first();
                }

                if (! $delivery) {
                    $delivery = TelegramMessage::query()
                        ->where('telegram_group_id', $group->id)
                        ->where('order_session_id', $session->id)
                        ->where('message_type', $messageType)
                        ->lockForUpdate()
                        ->first();
                }

                if ($delivery) {
                    $delivery->update(['idempotency_key' => $key]);
                } else {
                    $now = Carbon::now('UTC');
                    $inserted = DB::table('telegram_messages')->insertOrIgnore([
                        'telegram_group_id' => $group->id,
                        'order_session_id' => $session->id,
                        'message_type' => $messageType,
                        'idempotency_key' => $key,
                        'telegram_chat_id' => $group->telegram_chat_id,
                        'status' => 'pending',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $newDelivery = $inserted > 0;
                    $delivery = TelegramMessage::query()
                        ->where('idempotency_key', $key)
                        ->lockForUpdate()
                        ->first();
                }
            }

            if (! $delivery) {
                throw new \RuntimeException('Unable to claim Telegram notification delivery.');
            }

            if ($newDelivery) {
                return ['status' => 'claimed', 'delivery' => $delivery, 'error' => null];
            }
            if ($delivery->status === 'sent') {
                return ['status' => 'already_sent', 'delivery' => $delivery, 'error' => null];
            }
            if ($delivery->status === 'pending') {
                return ['status' => 'already_pending', 'delivery' => $delivery, 'error' => null];
            }
            if ($delivery->status === 'failed' && ! $retryFailed) {
                return [
                    'status' => 'failed',
                    'delivery' => $delivery,
                    'error' => $delivery->error_message,
                ];
            }

            $delivery->update([
                'telegram_chat_id' => $group->telegram_chat_id,
                'status' => 'pending',
                'error_message' => null,
            ]);

            return ['status' => 'claimed', 'delivery' => $delivery, 'error' => null];
        });
    }

    private function deliveryKey(TelegramGroup $group, OrderSession $session, string $messageType): string
    {
        return "session:{$session->id}:group:{$group->id}:{$messageType}";
    }

    private function splitTelegramMessageLines(array $lines): array
    {
        $messages = [];
        $current = '';

        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : "{$current}\n{$line}";
            if (mb_strlen($candidate) > 4000 && $current !== '') {
                $messages[] = $current;
                $current = $line;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $messages[] = $current;
        }

        return $messages;
    }

    private function formatCurrency(string|int|float $amount): string
    {
        return rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.');
    }

    private function durationMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
