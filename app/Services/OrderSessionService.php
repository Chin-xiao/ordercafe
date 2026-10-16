<?php

namespace App\Services;

use App\Models\OrderSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderSessionService
{
    public function __construct(private TelegramService $telegramService) {}

    public function createSession(User $admin, array $data): OrderSession
    {
        return OrderSession::create([
            'order_number' => 'SESSION-'.Carbon::now('UTC')->format('Ymd').'-'.str_pad(
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
        $this->expireSessions();

        $startedSession = DB::transaction(function () use ($session): OrderSession {
            $lockedSessions = OrderSession::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedSession = $lockedSessions->firstWhere('id', $session->id);

            if (! $lockedSession || $lockedSession->status !== 'draft') {
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

            if (! $expiresAt || $expiresAt->lessThanOrEqualTo($startedAt)) {
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

    public function retrySessionAnnouncement(OrderSession $session): array
    {
        return DB::transaction(function () use ($session): array {
            $lockedSession = OrderSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedSession || $lockedSession->status !== 'open') {
                throw ValidationException::withMessages([
                    'status' => ['Only an open session can retry its announcement.'],
                ]);
            }

            if (! $lockedSession->expires_at
                || $lockedSession->expires_at->lessThanOrEqualTo(Carbon::now('UTC'))) {
                throw ValidationException::withMessages([
                    'expires_at' => ['An announcement can only be retried before the session expires.'],
                ]);
            }

            return [
                'session' => $lockedSession,
                'notifications' => $this->telegramService->retryOrderStartedNotification($lockedSession),
            ];
        });
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

            if (! $lockedSession || $lockedSession->status !== 'open') {
                throw ValidationException::withMessages([
                    'status' => ['This session is not currently open.'],
                ]);
            }

            $lockedSession->update([
                'status' => 'closed',
                'closed_at' => Carbon::now('UTC'),
                'closed_by' => $admin->id,
            ]);

            return $this->sessionSummary($lockedSession->fresh());
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
        $sessionIds = OrderSession::query()
            ->where('status', 'open')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '<=', Carbon::now('UTC'));
            })
            ->orderBy('id')
            ->pluck('id');
        $expiredCount = 0;

        foreach ($sessionIds as $sessionId) {
            $summary = DB::transaction(function () use ($sessionId): ?array {
                $session = OrderSession::query()
                    ->whereKey($sessionId)
                    ->lockForUpdate()
                    ->first();

                if (! $session
                    || $session->status !== 'open'
                    || ($session->expires_at && $session->expires_at->isFuture())) {
                    return null;
                }

                $session->update([
                    'status' => 'expired',
                    'closed_at' => Carbon::now('UTC'),
                ]);

                return $this->sessionSummary($session->fresh());
            });

            if ($summary === null) {
                continue;
            }

            $expiredCount++;
            try {
                $notifications = $this->telegramService->sendOrderSummaryNotification($summary);
                $this->throwIfDeliveryFailed($notifications, [
                    'summary' => $summary,
                    'notifications' => $notifications,
                ]);
            } catch (TelegramDeliveryException $exception) {
                Log::warning('Expired order session summary could not be delivered.', [
                    'session_id' => $sessionId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $expiredCount;
    }

    public function sessionSummary(OrderSession $session): array
    {
        $orders = $session->orders()->where('status', 'submitted');
        $orderCount = (clone $orders)->count();
        $customerCount = (clone $orders)->distinct('user_id')->count('user_id');
        $totalRevenue = (clone $orders)->sum('total_amount');
        $productSummary = $session->orders()
            ->where('orders.status', 'submitted')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->selectRaw(
                'order_items.product_name, order_items.unit_price, order_items.sugar_percent, SUM(order_items.quantity) as total_quantity, SUM(order_items.subtotal) as subtotal'
            )
            ->groupBy('order_items.product_name', 'order_items.unit_price', 'order_items.sugar_percent')
            ->orderBy('order_items.product_name')
            ->get()
            ->map(fn ($item) => [
                'product_name' => $item->product_name,
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'sugar_percent' => $item->sugar_percent === null ? null : (int) $item->sugar_percent,
                'total_quantity' => (int) $item->total_quantity,
                'subtotal' => number_format((float) $item->subtotal, 2, '.', ''),
            ])
            ->all();

        return [
            'session' => $session,
            'total_customers' => $customerCount,
            'total_orders' => $orderCount,
            'total_revenue' => number_format((float) $totalRevenue, 2, '.', ''),
            'closing_status' => $session->status,
            'product_summary' => $productSummary,
        ];
    }

    private function throwIfDeliveryFailed(array $notifications, array $sessionData): void
    {
        foreach ($notifications as $notification) {
            if (in_array($notification['status'], ['failed', 'already_pending'], true)) {
                throw new TelegramDeliveryException(
                    'Telegram notification delivery failed or is still pending for one or more verified groups.',
                    $sessionData
                );
            }
        }
    }
}
