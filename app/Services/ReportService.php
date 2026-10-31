<?php
namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Get aggregate sales and revenue analytics within a date range.
     */
    public function getSalesSummary(?string $dateFrom, ?string $dateTo): array
    {
        $query = Order::where('status', 'submitted');

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $totalRevenue = (clone $query)->sum('total_amount');
        $totalOrders = (clone $query)->count();
        $uniqueCustomers = (clone $query)->distinct('user_id')->count('user_id');

        // Group revenue by day for charts
        $dailySales = (clone $query)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(*) as orders')
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        return [
            'total_revenue' => (float) $totalRevenue,
            'total_orders' => $totalOrders,
            'total_customers' => $uniqueCustomers,
            'daily_sales' => $dailySales,
        ];
    }

    /**
     * Get top-selling products grouped by quantity and revenue.
     */
    public function getProductPopularity(?string $dateFrom, ?string $dateTo): array
    {
        $query = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.status', 'submitted');

        $user = Auth::user();
        if ($user && $user->role === 'store_owner' && $user->store_id) {
            $query->where('orders.store_id', $user->store_id);
        }

        if ($dateFrom) {
            $query->whereDate('orders.created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('orders.created_at', '<=', $dateTo);
        }

        return $query->select(
                'order_items.product_name',
                DB::raw('SUM(order_items.quantity) as total_quantity'),
                DB::raw('SUM(order_items.subtotal) as total_revenue')
            )
            ->groupBy('order_items.product_name')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Get summary metrics for the Dashboard API.
     */
    public function getDashboardMetrics(): array
    {
        $today = now()->toDateString();

        $todayQuery = Order::where('status', 'submitted')->whereDate('created_at', $today);

        $todayOrders = $todayQuery->count();
        $todayCustomers = $todayQuery->distinct('user_id')->count('user_id');
        $todayRevenue = $todayQuery->sum('total_amount');

        $activeSession = OrderSession::where('status', 'open')->first();

        return [
            'today_orders' => $todayOrders,
            'today_customers' => $todayCustomers,
            'today_revenue' => (float) $todayRevenue,
            'active_session' => $activeSession,
        ];
    }

    public function getDetailedOrdersReport(array $filters, string $timezone): LengthAwarePaginator
    {
        $today = Carbon::now($timezone)->toDateString();
        $noDatesProvided = ! isset($filters['date_from']) && ! isset($filters['date_to']);
        $dateFrom = $filters['date_from'] ?? ($noDatesProvided ? $today : null);
        $dateTo = $filters['date_to'] ?? ($noDatesProvided ? $today : null);
        $from = $dateFrom
            ? Carbon::parse($dateFrom, $timezone)->startOfDay()->utc()
            : null;
        $toExclusive = $dateTo
            ? Carbon::parse($dateTo, $timezone)->addDay()->startOfDay()->utc()
            : null;

        $query = Order::query()
            ->with(['user', 'session', 'items'])
            ->when(! isset($filters['status']), fn ($orders) => $orders->where('status', 'submitted'))
            ->when(isset($filters['order_session_id']), fn ($orders) => $orders->where(
                'order_session_id',
                $filters['order_session_id']
            ))
            ->when(isset($filters['status']), fn ($orders) => $orders->where('status', $filters['status']))
            ->when($from, fn ($orders) => $orders->where('submitted_at', '>=', $from))
            ->when($toExclusive, fn ($orders) => $orders->where('submitted_at', '<', $toExclusive));

        if (! empty($filters['search'])) {
            $search = trim($filters['search']);
            $like = '%'.$search.'%';
            $usernameLike = '%'.ltrim($search, '@').'%';

            $query->whereHas('user', function ($users) use ($like, $usernameLike): void {
                $users->whereRaw('CAST(telegram_id AS TEXT) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(username) LIKE LOWER(?)', [$usernameLike])
                    ->orWhereRaw('LOWER(name) LIKE LOWER(?)', [$like])
                    ->orWhereRaw('LOWER(first_name) LIKE LOWER(?)', [$like])
                    ->orWhereRaw('LOWER(last_name) LIKE LOWER(?)', [$like])
                    ->orWhereRaw(
                        "LOWER(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')) LIKE LOWER(?)",
                        [$like]
                    );
            });
        }

        return $query
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15)
            ->through(function (Order $order) use ($timezone): array {
                $name = trim(implode(' ', array_filter([
                    $order->user?->first_name,
                    $order->user?->last_name,
                ])));
                $itemGroups = $order->items->groupBy(fn (OrderItem $item): string => json_encode([
                    $item->product_id ?? $item->product_name,
                    $item->sugar_percent,
                    $item->unit_price,
                ], JSON_THROW_ON_ERROR));

                return [
                    'customer' => [
                        'id' => $order->user?->id,
                        'display_name' => $name ?: $order->user?->name,
                        'telegram_username' => $order->user?->username
                            ? '@'.ltrim($order->user->username, '@')
                            : null,
                        'telegram_user_id' => $order->user?->telegram_id,
                    ],
                    'order' => [
                        'id' => $order->id,
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                        'order_time' => $order->submitted_at
                            ? $order->submitted_at->copy()->setTimezone($timezone)->toIso8601String()
                            : null,
                        'session' => [
                            'id' => $order->session?->id,
                            'name' => $order->session?->title,
                            'status' => $order->session?->status,
                        ],
                        'order_total' => $order->total_amount,
                    ],
                    'items' => $itemGroups->map(function ($items): array {
                        /** @var OrderItem $item */
                        $item = $items->first();

                        return [
                            'product_id' => $item->product_id,
                            'drink_name' => $item->product_name,
                            'sugar_percent' => $item->sugar_percent,
                            'quantity' => (int) $items->sum('quantity'),
                            'unit_price' => $item->unit_price,
                            'item_subtotal' => number_format(
                                (float) $items->sum(fn (OrderItem $orderItem) => (float) $orderItem->subtotal),
                                2,
                                '.',
                                ''
                            ),
                        ];
                    })->values()->all(),
                ];
            });
    }
}
