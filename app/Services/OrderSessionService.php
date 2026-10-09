<?php

namespace App\Services;

use App\Models\OrderSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderSessionService
{
    public function __construct(private TelegramService $telegramService)
    {
    }

    public function createSession(User $admin, array $data): OrderSession
    {
        return OrderSession::create([
            'order_number' => 'SESSION-' . Carbon::now('UTC')->format('Ymd') . '-' . str_pad(
                (string) random_int(1, 999),
                3,
                '0',
                STR_PAD_LEFT
            ),
            'title' => $data['title'],
            'scheduled_start_at' => $data['scheduled_start_at'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'duration_minutes' => $data['duration_minutes'] ?? null,
            'announcement_message' => $data['announcement_message'] ?? null,
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
    }

    /**
     * @return array{session: OrderSession, notifications: array}
     */
    public function startSession(OrderSession $session): array
    {
        $startedSession = DB::transaction(function () use ($session): OrderSession {
            $lockedSessions = OrderSession::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedSession = $lockedSessions->firstWhere('id', $session->id);

            if (!$lockedSession || $lockedSession->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => ['Only draft sessions can be started.'],
                ]);
            }

            if ($lockedSession->scheduled_start_at
                && $lockedSession->scheduled_start_at->isFuture()) {
                throw ValidationException::withMessages([
                    'scheduled_start_at' => ['This session cannot start before its scheduled start time.'],
                ]);
            }

            $now = Carbon::now('UTC');
            foreach ($lockedSessions as $candidate) {
                if ($candidate->status === 'open'
                    && (!$candidate->expires_at || $candidate->expires_at->lessThanOrEqualTo($now))) {
                    $candidate->update(['status' => 'expired']);
                }
            }

            if ($lockedSessions->contains(fn (OrderSession $candidate) => $candidate->status === 'open')) {
                throw ValidationException::withMessages([
                    'status' => ['Another order session is already open.'],
                ]);
            }

            $startedAt = $now;
            $expiresAt = $lockedSession->expires_at
                ?? ($lockedSession->duration_minutes
                    ? $startedAt->copy()->addMinutes($lockedSession->duration_minutes)
                    : null);

            if (!$expiresAt || $expiresAt->lessThanOrEqualTo($startedAt)) {
                throw ValidationException::withMessages([
                    'expires_at' => ['The session must have a future expiration time or duration.'],
                ]);
            }

            $lockedSession->update([
                'status' => 'open',
                'started_at' => $startedAt,
                'expires_at' => $expiresAt,
            ]);

            return $lockedSession->fresh();
        });

        $notifications = $this->telegramService->sendOrderStartedNotification($startedSession);
        $this->throwIfDeliveryFailed($notifications, [
            'session' => $startedSession,
            'notifications' => $notifications,
        ]);

        return [
            'session' => $startedSession->fresh(),
            'notifications' => $notifications,
        ];
    }

    /**
     * @return array{summary: array, notifications: array}
     */
    public function closeSession(OrderSession $session, User $admin): array
    {
        $summary = DB::transaction(function () use ($session, $admin): array {
            $lockedSession = OrderSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            if (!$lockedSession || $lockedSession->status !== 'open') {
                throw ValidationException::withMessages([
                    'status' => ['This session is not currently open.'],
                ]);
            }

            $lockedSession->update([
                'status' => 'closed',
                'closed_at' => Carbon::now('UTC'),
                'closed_by' => $admin->id,
            ]);

            $orders = $lockedSession->orders()
                ->where('status', 'submitted')
                ->with('items')
                ->get();
            $productSummary = [];

            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    $key = ($item->product_id ?? $item->product_name) . ':' . $item->unit_price;
                    if (!isset($productSummary[$key])) {
                        $productSummary[$key] = [
                            'product_name' => $item->product_name,
                            'unit_price' => $item->unit_price,
                            'total_quantity' => 0,
                            'subtotal' => '0.00',
                            'subtotal_cents' => 0,
                        ];
                    }

                    $productSummary[$key]['total_quantity'] += $item->quantity;
                    $productSummary[$key]['subtotal_cents'] += (int) round((float) $item->subtotal * 100);
                }
            }

            foreach ($productSummary as &$item) {
                $item['subtotal'] = number_format($item['subtotal_cents'] / 100, 2, '.', '');
                unset($item['subtotal_cents']);
            }
            unset($item);

            $totalRevenueCents = $orders->sum(
                fn ($order) => (int) round((float) $order->total_amount * 100)
            );

            return [
                'session' => $lockedSession->fresh(),
                'total_customers' => $orders->pluck('user_id')->unique()->count(),
                'total_orders' => $orders->count(),
                'total_revenue' => number_format($totalRevenueCents / 100, 2, '.', ''),
                'product_summary' => array_values($productSummary),
            ];
        });

        $notifications = $this->telegramService->sendOrderSummaryNotification($summary);
        $this->throwIfDeliveryFailed($notifications, [
            'summary' => $summary,
            'notifications' => $notifications,
        ]);

        return [
            'summary' => $summary,
            'notifications' => $notifications,
        ];
    }

    public function expireSessions(): int
    {
        return OrderSession::query()
            ->where('status', 'open')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '<=', Carbon::now('UTC'));
            })
            ->update(['status' => 'expired']);
    }

    private function throwIfDeliveryFailed(array $notifications, array $sessionData): void
    {
        foreach ($notifications as $notification) {
            if ($notification['status'] === 'failed') {
                throw new TelegramDeliveryException(
                    'Telegram notification delivery failed for one or more verified groups.',
                    $sessionData
                );
            }
        }
    }
}
