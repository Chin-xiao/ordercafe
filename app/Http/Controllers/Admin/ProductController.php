<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\MiniAppCatalogCache;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('category')
            ->orderBy('sort_order')
            ->orderBy('name');

        // Allow Super Admin to filter by a specific store query parameter if needed
        if ($request->has('store_id') && $request->user()->role === 'super_admin') {
            $query->where('store_id', $request->input('store_id'));
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);

        // Assign store_id based on SuperAdmin input or authenticated store owner
        if ($request->user()->role === 'super_admin' && $request->has('store_id')) {
            $data['store_id'] = $request->input('store_id');
        } elseif (empty($data['store_id'])) {
            $data['store_id'] = $request->user()->store_id ?? 1;
        }

        $product = Product::create($data);

        // Instantly invalidate/clear the miniapp cache for this store
        MiniAppCatalogCache::forget($product->store_id);

        return response()->json([
            'data' => $product->load('category'),
        ], 201);
    }

    public function show(Product $product)
    {
        return response()->json(['data' => $product->load('category')]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $this->validatedData($request, partial: true);

        if ($request->user()->role === 'super_admin' && $request->has('store_id')) {
            $data['store_id'] = $request->input('store_id');
        }

        $product->update($data);

        // Instantly invalidate/clear the miniapp cache for this store
        MiniAppCatalogCache::forget($product->store_id);

        return response()->json(['data' => $product->refresh()->load('category')]);
    }

    public function destroy(Product $product)
    {
        $storeId = $product->store_id;
        $product->delete();

        // Instantly invalidate/clear the miniapp cache for this store
        MiniAppCatalogCache::forget($storeId);

        return response()->noContent();
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $rules = [
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['numeric', 'min:0', 'decimal:0,2'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'is_available' => ['boolean'],
            'sort_order' => ['integer'],
            'requires_sugar' => ['boolean'],
        ];

        if (!$partial) {
            $rules['name'][] = 'required';
            $rules['price'][] = 'required';
        } else {
            foreach (['store_id', 'name', 'price', 'category_id', 'description', 'image_url', 'is_available', 'sort_order', 'requires_sugar'] as $field) {
                $rules[$field][] = 'sometimes';
            }
        }

        return $request->validate($rules);
    }
}
