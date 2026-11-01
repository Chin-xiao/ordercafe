<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Services\MiniAppCatalogCache;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        // Get store ID safely from the authenticated user context (default to 1 if null)
        $storeId = optional($request->user())->store_id ?? 1;

        $catalog = MiniAppCatalogCache::remember(
            MiniAppCatalogCache::PRODUCTS_KEY,
            $storeId,
            fn () => [
                'data' => Product::query()
                    ->where('is_available', true)
                    ->where(function ($query) {
                        $query->whereNull('category_id')
                            ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('is_active', true));
                    })
                    ->with('category')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->toArray(),
                'sugar_percent_options' => OrderItem::allowedSugarPercentages(),
            ]
        );
        $catalog['private_messaging'] = $this->privateMessagingDetails($request->user());

        return response()->json($catalog);
    }

    public function categories(Request $request)
    {
        $storeId = optional($request->user())->store_id ?? 1;

        $catalog = MiniAppCatalogCache::remember(
            MiniAppCatalogCache::CATEGORIES_KEY,
            $storeId,
            fn () => [
                'data' => Category::query()
                    ->where('is_active', true)
                    ->with(['products' => fn ($query) => $query
                        ->where('is_available', true)
                        ->orderBy('sort_order')
                        ->orderBy('name')])
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get()
                    ->toArray(),
                'sugar_percent_options' => OrderItem::allowedSugarPercentages(),
            ]
        );
        $catalog['private_messaging'] = $this->privateMessagingDetails($request->user());

        return response()->json($catalog);
    }

    private function privateMessagingDetails(?User $customer): array
    {
        if (!$customer) {
            return [
                'activated' => false,
                'activation_url' => null,
            ];
        }

        $activated = $customer->telegram_private_chat_id !== null
            && $customer->telegram_private_chat_activated_at !== null;
        $botUsername = ltrim((string) config('services.telegram.bot_username', 'ahseven_bot'), '@');

        return [
            'activated' => $activated,
            'activation_url' => $activated
                ? null
                : "https://t.me/{$botUsername}?start=order_notifications",
        ];
    }
}
