<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Product::with('category')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $product = Product::create($this->validatedData($request));

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
        $product->update($this->validatedData($request, partial: true));

        return response()->json(['data' => $product->refresh()->load('category')]);
    }

    public function destroy(Product $product)
    {
        $product->delete();

        return response()->noContent();
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $rules = [
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['numeric', 'min:0', 'decimal:0,2'],
            'image_url' => ['nullable', 'url', 'max:2048'],
            'is_available' => ['boolean'],
            'sort_order' => ['integer'],
        ];

        if (!$partial) {
            $rules['name'][] = 'required';
            $rules['price'][] = 'required';
        } else {
            foreach (['name', 'price', 'category_id', 'description', 'image_url', 'is_available', 'sort_order'] as $field) {
                $rules[$field][] = 'sometimes';
            }
        }

        return $request->validate($rules);
    }
}
