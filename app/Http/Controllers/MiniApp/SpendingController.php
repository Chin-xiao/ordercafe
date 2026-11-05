<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SpendingController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $storeId = $user->store_id ?? 1; // Strict store isolation
        $range = $request->query('range', 'month');
        $now = Carbon::now();

        // 1. Base query: Get successful orders for this specific customer and store
        $query = Order::with('items.product.category')
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->where('status', '!=', 'cancelled'); // "Cancelled orders are not counted"

        // 2. Filter by requested date range
        if ($range === 'week') {
            $query->where('created_at', '>=', $now->copy()->startOfWeek());
        } elseif ($range === 'month') {
            $query->where('created_at', '>=', $now->copy()->startOfMonth());
        }

        $orders = $query->get();

        // 3. Initialize data structures
        $dailyTotals = [];
        $categoryTotals = [];
        $itemCounts = [];

        // Pre-fill daily chart so days with 0 spend still show up on the graph
        if ($range === 'week') {
            for ($i = 0; $i < 7; $i++) {
                $d = $now->copy()->startOfWeek()->addDays($i);
                $dailyTotals[$d->format('Y-m-d')] = ['label' => $d->format('D'), 'total' => 0];
            }
        } elseif ($range === 'month') {
            for ($i = 1; $i <= $now->daysInMonth; $i++) {
                $d = $now->copy()->startOfMonth()->addDays($i - 1);
                $dailyTotals[$d->format('Y-m-d')] = ['label' => $d->format('j M'), 'total' => 0];
            }
        }

        // 4. Aggregate the data
        foreach ($orders as $order) {
            $dateKey = $order->created_at->format('Y-m-d');

            // Daily Chart
            if (isset($dailyTotals[$dateKey])) {
                $dailyTotals[$dateKey]['total'] += (float) $order->total_price;
            } else {
                $dailyTotals[$dateKey] = [
                    'label' => $order->created_at->format('M d'),
                    'total' => (float) $order->total_price
                ];
            }

            // Iterate items for Category & Top Item breakdown
            foreach ($order->items as $item) {
                // Top Items
                $itemName = $item->product_name ?? optional($item->product)->name ?? 'Unknown Drink';
                $itemCounts[$itemName] = ($itemCounts[$itemName] ?? 0) + $item->quantity;

                // Categories
                $catName = optional(optional($item->product)->category)->name ?? 'Uncategorized';
                $lineTotal = (float) $item->price * $item->quantity;
                $categoryTotals[$catName] = ($categoryTotals[$catName] ?? 0) + $lineTotal;
            }
        }

        // 5. Format arrays and sort them
        $topItems = collect($itemCounts)
            ->map(fn($qty, $name) => ['name' => $name, 'quantity' => $qty])
            ->sortByDesc('quantity')->take(5)->values();

        $byCategory = collect($categoryTotals)
            ->map(fn($tot, $name) => ['name' => $name, 'total' => $tot])
            ->sortByDesc('total')->values();

        // Ensure chart is ordered by date
        ksort($dailyTotals);

        return response()->json([
            'data' => [
                'total' => $orders->sum('total_price'),
                'orders_count' => $orders->count(),
                'daily' => array_values($dailyTotals),
                'by_category' => $byCategory,
                'top_items' => $topItems,
            ]
        ]);
    }
}
