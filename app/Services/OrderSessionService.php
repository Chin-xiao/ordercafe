<?php
namespace App\Services;

use App\Models\OrderSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderSessionService
{
    protected TelegramService $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }
    /**
     * Create a new order session in draft status.
     */
    public function createSession(User $admin, array $data): OrderSession
    {
        $dateStr = date('Ymd');
        $randomNum = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);

        return OrderSession::create([
            'order_number' => "SESSION-{$dateStr}-{$randomNum}",
            'title' => $data['title'],
            'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
    }

    /**
     * Start an order session (Transition draft -> open)
     */
    public function startSession(OrderSession $session): OrderSession
    {
        return DB::transaction(function () use ($session) {
            if ($session->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => ['Only draft sessions can be started.'],
                ]);
            }

            $activeExists = OrderSession::where('status', 'open')->exists();
            if ($activeExists) {
                throw ValidationException::withMessages([
                    'status' => ['Another order session is already open.'],
                ]);
            }

            $session->update([
                'status' => 'open',
                'started_at' => Carbon::now(),
            ]);

            // 🚀 Automatically broadcast Telegram open notification
            $this->telegramService->sendOrderStartedNotification($session);

            return $session;
        });
    }

    public function closeSession(OrderSession $session, User $admin): array
    {
        return DB::transaction(function () use ($session, $admin) {
            $lockedSession = OrderSession::where('id', $session->id)->lockForUpdate()->first();

            if ($lockedSession->status !== 'open') {
                throw ValidationException::withMessages([
                    'status' => ['This session is not currently open.'],
                ]);
            }

            $lockedSession->update([
                'status' => 'closed',
                'closed_at' => Carbon::now(),
                'closed_by' => $admin->id,
            ]);

            $orders = $lockedSession->orders()->with('items')->get();
            $totalCustomers = $orders->count();
            $totalRevenue = $orders->sum('total_amount');

            $productSummary = [];
            foreach ($orders as $order) {
                foreach ($order->items as $item) {
                    $pId = $item->product_id;
                    if (!isset($productSummary[$pId])) {
                        $productSummary[$pId] = [
                            'product_name' => $item->product_name,
                            'unit_price' => $item->unit_price,
                            'total_quantity' => 0,
                            'subtotal' => 0,
                        ];
                    }
                    $productSummary[$pId]['total_quantity'] += $item->quantity;
                    $productSummary[$pId]['subtotal'] += $item->subtotal;
                }
            }

            $summaryData = [
                'session' => $lockedSession,
                'total_customers' => $totalCustomers,
                'total_orders' => $totalCustomers,
                'total_revenue' => $totalRevenue,
                'product_summary' => array_values($productSummary),
            ];

            // 🚀 Automatically broadcast Telegram summary notification
            $this->telegramService->sendOrderSummaryNotification($summaryData);

            return $summaryData;
        });
    }
}
