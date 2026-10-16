<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\MiniAppCatalogCache;

class ProductController extends Controller
{
    public function index()
    {
        return response()->json(MiniAppCatalogCache::remember(
            MiniAppCatalogCache::PRODUCTS_KEY,
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
        ));
    }

    public function categories()
    {
        return response()->json(MiniAppCatalogCache::remember(
            MiniAppCatalogCache::CATEGORIES_KEY,
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
        ));
    }
}
