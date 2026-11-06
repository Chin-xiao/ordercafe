<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class StoreController extends Controller
{
    // List all stores with counts
    public function index()
    {
        $stores = Store::withCount(['products', 'categories', 'orderSessions', 'orders'])
            ->with('users')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $stores
        ]);
    }

    // Create a new store and its owner account (optionally auto-seeds default categories)
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:stores,slug',
            'owner_name' => 'required|string|max:255',
            'owner_email' => 'required|email|unique:users,email',
            'owner_password' => 'required|string|min:6',
            'auto_seed_categories' => 'nullable|boolean',
        ]);

        $slug = $request->slug ? Str::slug($request->slug) : Str::slug($request->name);

        // Ensure unique slug
        $originalSlug = $slug;
        $counter = 1;
        while (Store::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }

        $autoSeedCategories = $request->boolean('auto_seed_categories', true);

        $store = DB::transaction(function () use ($request, $slug, $autoSeedCategories) {
            // 1. Create the Store
            $store = Store::create([
                'name' => $request->name,
                'slug' => $slug,
                'is_active' => true,
                'auto_seed_categories' => $autoSeedCategories,
            ]);

            // 2. Create the Store Owner user account
            User::create([
                'name' => $request->owner_name,
                'email' => $request->owner_email,
                'password' => Hash::make($request->owner_password),
                'role' => 'store_owner',
                'store_id' => $store->id,
            ]);

            // 3. Auto-seed default categories if enabled
            if ($autoSeedCategories) {
                Category::create([
                    'name' => 'Drink',
                    'store_id' => $store->id,
                    'is_active' => true,
                    'sort_order' => 1,
                ]);

                Category::create([
                    'name' => 'Snacks',
                    'store_id' => $store->id,
                    'is_active' => true,
                    'sort_order' => 2,
                ]);
            }

            return $store;
        });

        return response()->json([
            'success' => true,
            'message' => $autoSeedCategories
                ? 'Store system created successfully with default categories (Drink & Snacks)!'
                : 'Store system created successfully with an empty catalog!',
            'data' => $store->load(['categories', 'users'])
        ], 201);
    }
}
