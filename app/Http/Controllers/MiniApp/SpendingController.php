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
        $storeId = $user->store_id ?? 1;
        $range = $request->query('range', 'month');
        $now = Carbon::now();

        $query = Order::with('items.product.category')
            ->where('user_id', $user->id)
            ->where('store_id', $storeId)
            ->where('status', '!=', 'cancelled');

        if ($range === 'week') {
            $query->where('created_at', '>=', $now->copy()->startOfWeek());
        } elseif ($range === 'month') {
            $query->where('created_at', '>=', $now->copy()->startOfMonth());
        }

        $orders = $query->get();

        $dailyTotals = [];
        $categoryTotals = [];
        $itemCounts = [];
        $grandTotal = 0;

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

        foreach ($orders as $order) {
            $dateKey = $order->created_at->format('Y-m-d');

            // Fallback chain for the order's total amount column
            $orderTotal = (float) ($order->total_price ?? $order->total ?? $order->amount ?? 0);
            $grandTotal += $orderTotal;

            if (isset($dailyTotals[$dateKey])) {
                $dailyTotals[$dateKey]['total'] += $orderTotal;
            } else {
                $dailyTotals[$dateKey] = [
                    'label' => $order->created_at->format('M d'),
                    'total' => $orderTotal
                ];
            }

            foreach ($order->items as $item) {
                $itemName = $item->product_name ?? optional($item->product)->name ?? 'Unknown Drink';
                $itemCounts[$itemName] = ($itemCounts[$itemName] ?? 0) + $item->quantity;

                $catName = optional(optional($item->product)->category)->name ?? 'Uncategorized';

                // Fallback chain for the item's price column
                $itemPrice = (float) ($item->price ?? $item->unit_price ?? optional($item->product)->price ?? 0);
                $lineTotal = $itemPrice * $item->quantity;

                $categoryTotals[$catName] = ($categoryTotals[$catName] ?? 0) + $lineTotal;
            }
        }

        $topItems = collect($itemCounts)
            ->map(fn($qty, $name) => ['name' => $name, 'quantity' => $qty])
            ->sortByDesc('quantity')->take(5)->values();

        $byCategory = collect($categoryTotals)
            ->map(fn($tot, $name) => ['name' => $name, 'total' => $tot])
            ->sortByDesc('total')->values();

        ksort($dailyTotals);

        return response()->json([
            'data' => [
                'total' => $grandTotal,
                'orders_count' => $orders->count(),
                'daily' => array_values($dailyTotals),
                'by_category' => $byCategory,
                'top_items' => $topItems,
            ]
        ]);
    }
}
