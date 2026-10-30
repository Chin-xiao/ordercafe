<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TelegramGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TelegramSettingsController extends Controller
{
    public function show(Request $request)
    {
        $storeId = $request->user()->store_id;

        $linkedGroup = $this->linkedGroup($storeId);
        $pendingGroup = TelegramGroup::query()
            ->where('store_id', $storeId)
            ->where('is_verified', false)
            ->whereNull('telegram_chat_id')
            ->whereNotNull('verify_token')
            ->latest('id')
            ->first();

        if (! $pendingGroup && ! $linkedGroup) {
            $pendingGroup = $this->createPendingGroup($storeId);
        }

        return response()->json([
            'success' => true,
            'data' => $this->settingsData($linkedGroup, $pendingGroup),
        ]);
    }

    public function regenerate(Request $request)
    {
        $storeId = $request->user()->store_id;
        $group = $this->createPendingGroup($storeId);

        return response()->json([
            'success' => true,
            'message' => 'New verification token generated.',
            'data' => $this->settingsData($this->linkedGroup($storeId), $group),
        ]);
    }

    private function linkedGroup($storeId): ?TelegramGroup
    {
        return TelegramGroup::query()
            ->where('store_id', $storeId)
            ->where('is_active', true)
            ->where('is_verified', true)
            ->whereNotNull('telegram_chat_id')
            ->latest('id')
            ->first();
    }

    private function settingsData(?TelegramGroup $linkedGroup, ?TelegramGroup $pendingGroup): array
    {
        return [
            'verify_token' => $pendingGroup?->verify_token,
            'setup_command' => $pendingGroup ? $this->setupCommand($pendingGroup) : null,
            'is_verified' => $linkedGroup !== null,
            'group_name' => $linkedGroup?->group_name ?: $linkedGroup?->title,
            'chat_id' => $linkedGroup?->telegram_chat_id,
            'linked_group' => $linkedGroup ? [
                'id' => $linkedGroup->id,
                'group_name' => $linkedGroup->group_name ?: $linkedGroup->title,
                'chat_id' => $linkedGroup->telegram_chat_id,
            ] : null,
            'pending_setup' => $pendingGroup ? [
                'verify_token' => $pendingGroup->verify_token,
                'setup_command' => $this->setupCommand($pendingGroup),
            ] : null,
        ];
    }

    private function createPendingGroup($storeId): TelegramGroup
    {
        return TelegramGroup::create([
            'store_id' => $storeId,
            'verify_token' => 'CAFE_'.strtoupper(Str::random(32)),
            'is_verified' => false,
            'is_active' => true,
        ]);
    }

    private function setupCommand(TelegramGroup $group): string
    {
        $botUsername = ltrim((string) config('services.telegram.bot_username', 'ahseven_bot'), '@');

        return "/setup@{$botUsername} {$group->verify_token}";
    }
}
