<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Category::withCount('products')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $category = Category::create($this->validatedData($request));

        return response()->json(['data' => $category], 201);
    }

    public function show(Category $category)
    {
        return response()->json(['data' => $category->loadCount('products')]);
    }

    public function update(Request $request, Category $category)
    {
        $category->update($this->validatedData($request, partial: true));

        return response()->json(['data' => $category->refresh()->loadCount('products')]);
    }

    public function destroy(Category $category)
    {
        $category->delete();

        return response()->noContent();
    }

    private function validatedData(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => ['string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'sort_order' => ['integer'],
        ];

        if (!$partial) {
            $rules['name'][] = 'required';
        } else {
            foreach (array_keys($rules) as $field) {
                $rules[$field][] = 'sometimes';
            }
        }

        return $request->validate($rules);
    }
}
