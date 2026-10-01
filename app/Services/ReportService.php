<?php
namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
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

        $totalRevenue = $query->sum('total_amount');
        $totalOrders = $query->count();
        $uniqueCustomers = $query->distinct('user_id')->count('user_id');

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
            ->get();
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
}
