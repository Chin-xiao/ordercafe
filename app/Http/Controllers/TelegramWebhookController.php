<?php

namespace App\Http\Controllers;

use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\TelegramCustomerIdentity;
use App\Services\TelegramDeliveryException;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private TelegramService $telegramService,
        private TelegramCustomerIdentity $customerIdentity
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = config('services.telegram.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            Log::error('Telegram webhook rejected because its secret is not configured.');

            return response()->json([
                'message' => 'Telegram webhook is not configured.',
                'error_code' => 'WEBHOOK_NOT_CONFIGURED',
            ], 503);
        }

        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');
        if (! is_string($providedSecret) || ! hash_equals($secret, $providedSecret)) {
            Log::warning('Telegram webhook request failed secret verification.');

            return response()->json([
                'message' => 'Unauthorized Telegram webhook request.',
                'error_code' => 'WEBHOOK_UNAUTHORIZED',
            ], 401);
        }

        $update = $request->all();
        $updateId = $update['update_id'] ?? null;
        if (! is_int($updateId) && (! is_string($updateId) || ! ctype_digit($updateId))) {
            return response()->json([
                'message' => 'Telegram update ID is missing or invalid.',
                'error_code' => 'INVALID_UPDATE_ID',
            ], 400);
        }
        $updateId = (int) $updateId;

        if (! $this->claimUpdate($updateId)) {
            return response()->json(['status' => 'ok', 'duplicate' => true]);
        }

        try {
            $this->processPrivateStart($update);
            $this->processGroupSetup($update);
            DB::table('telegram_webhook_updates')
                ->where('update_id', $updateId)
                ->update([
                    'status' => 'processed',
                    'processed_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
        } catch (TelegramDeliveryException $exception) {
            $this->markUpdateFailed($updateId);
            Log::warning('Telegram webhook message processing failed.', [
                'update_id' => $updateId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'Telegram update could not be processed.',
                'error_code' => 'TELEGRAM_DELIVERY_FAILED',
            ], 502);
        } catch (Throwable $exception) {
            $this->markUpdateFailed($updateId);
            Log::error('Telegram webhook update processing failed.', [
                'update_id' => $updateId,
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        return response()->json(['status' => 'ok']);
    }

    private function claimUpdate(int $updateId): bool
    {
        return DB::transaction(function () use ($updateId): bool {
            $now = now('UTC');
            $inserted = DB::table('telegram_webhook_updates')->insertOrIgnore([
                'update_id' => $updateId,
                'status' => 'processing',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $update = DB::table('telegram_webhook_updates')
                ->where('update_id', $updateId)
                ->lockForUpdate()
                ->first();

            if (! $update) {
                throw new \RuntimeException('Unable to claim Telegram webhook update.');
            }

            if ($inserted > 0) {
                return true;
            }
            $staleProcessing = $update->status === 'processing'
                && $update->updated_at !== null
                && now('UTC')->diffInSeconds($update->updated_at) >= 120;
            if ($update->status !== 'failed' && ! $staleProcessing) {
                return false;
            }

            DB::table('telegram_webhook_updates')
                ->where('update_id', $updateId)
                ->update([
                    'status' => 'processing',
                    'updated_at' => $now,
                ]);

            return true;
        });
    }

    private function processPrivateStart(array $update): void
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ! is_string($message['text'] ?? null)) {
            return;
        }

        if (! preg_match('/^\\/start(?:@[A-Za-z0-9_]+)?(?:\\s+[A-Za-z0-9_-]+)?\\s*$/D', trim($message['text']))) {
            return;
        }

        $chat = $message['chat'] ?? null;
        $sender = $message['from'] ?? null;
        if (! is_array($chat)
            || ($chat['type'] ?? null) !== 'private'
            || ! is_array($sender)
            || ($sender['is_bot'] ?? false) === true
            || ! $this->isTelegramId($chat['id'] ?? null)
            || ! $this->isTelegramId($sender['id'] ?? null)
            || (int) $chat['id'] <= 0
            || (int) $sender['id'] <= 0) {
            return;
        }

        $customer = User::query()
            ->where('telegram_id', (int) $sender['id'])
            ->where('is_admin', false)
            ->first();

        if (! $customer) {
            $this->telegramService->sendMessage(
                (int) $chat['id'],
                'Please open the Mini App from your cafe group first. Once your verified customer profile exists, send /start again to enable private messages.'
            );

            return;
        }

        $chatAlreadyLinked = User::query()
            ->where('telegram_private_chat_id', (int) $chat['id'])
            ->whereKeyNot($customer->id)
            ->exists();

        if ($chatAlreadyLinked) {
            $this->telegramService->sendMessage(
                (int) $chat['id'],
                'This private chat could not be linked to your customer account. Please contact an administrator.'
            );

            return;
        }

        $customer = DB::transaction(function () use ($chat, $sender, $customer): User {
            $customer = $this->customerIdentity->sync($sender);
            $chatId = (int) $chat['id'];
            $customer->forceFill([
                'telegram_private_chat_id' => $chatId,
                'telegram_private_chat_activated_at' => $customer->telegram_private_chat_id === $chatId
                    ? ($customer->telegram_private_chat_activated_at ?? now('UTC'))
                    : now('UTC'),
            ])->save();

            return $customer;
        });

        $this->telegramService->sendMessage(
            $customer->telegram_private_chat_id,
            'Thanks! Private order notifications are now enabled. You can continue ordering in the Mini App.'
        );
    }

    private function processGroupSetup(array $update): void
    {
        $message = $update['message'] ?? null;
        if (! is_array($message) || ! is_string($message['text'] ?? null)) {
            return;
        }

        $chat = $message['chat'] ?? null;
        if (! is_array($chat)
            || ! in_array($chat['type'] ?? null, ['group', 'supergroup'], true)
            || ! $this->isTelegramId($chat['id'] ?? null)
            || (int) $chat['id'] >= 0) {
            return;
        }

       $text = trim($message['text']);
        if (! preg_match('/^\\/setup(?:@([A-Za-z0-9_]+))?(?:\\s+([A-Za-z0-9_-]+))?\\s*$/Du', $text, $matches)) {
            return;
        }

        $targetBot = $matches[1] ?? null;
        $botUsername = ltrim((string) config('services.telegram.bot_username', 'ahseven_bot'), '@');
        if (is_string($targetBot) && $targetBot !== '' && strcasecmp($targetBot, $botUsername) !== 0) {
            return;
        }

        $token = trim($matches[2] ?? '');
        $group = TelegramGroup::query()->where('verify_token', $token)->first();
        if (! $group) {
            $this->telegramService->sendMessage(
                (int) $chat['id'],
                'Invalid or expired group verification token. Generate a new token in Admin settings.'
            );

            return;
        }

        if ($group->is_verified && (int) $group->telegram_chat_id !== (int) $chat['id']) {
            $this->telegramService->sendMessage(
                (int) $chat['id'],
                'This verification token has already been used. Generate a new token in Admin settings for another group.'
            );

            return;
        }

        $chatAlreadyLinked = TelegramGroup::query()
            ->where('telegram_chat_id', (int) $chat['id'])
            ->whereKeyNot($group->id)
            ->exists();

        if ($chatAlreadyLinked) {
            $this->telegramService->sendMessage(
                (int) $chat['id'],
                'This Telegram group is already linked to another verification record.'
            );

            return;
        }

        $groupName = is_string($chat['title'] ?? null) ? $chat['title'] : 'Telegram Group';
        DB::transaction(function () use ($group, $chat, $groupName): void {
            $group->telegram_chat_id = (int) $chat['id'];
            $group->title = $groupName;
            $group->group_name = $groupName;
            $group->is_verified = true;
            $group->save();
        });

        // Wrap the outgoing Telegram API call in try/catch so a network glitch
        // doesn't rollback your database group verification!
        try {
            $this->telegramService->sendMessage(
                (int) $chat['id'],
                'Group successfully verified and linked to the Cafe Ordering system.'
            );
        } catch (\Throwable $e) {
            Log::error('Failed to send Telegram group verification message: ' . $e->getMessage());
        }
    }

    private function markUpdateFailed(int $updateId): void
    {
        DB::table('telegram_webhook_updates')
            ->where('update_id', $updateId)
            ->update([
                'status' => 'failed',
                'updated_at' => now('UTC'),
            ]);
    }

    private function isTelegramId(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1);
    }
}
