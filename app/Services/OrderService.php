<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderSession;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    /**
     * Submit an order for a user within an active order session.
     *
     * @param User $user The authenticated Telegram user
     * @param array $data Contains order_session_id and items (product_id, quantity)
     * @return Order
     * @throws ValidationException
     */
    public function submitOrder(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data) {
            // 1. Find and lock the order session to prevent race conditions
            $session = OrderSession::where('id', $data['order_session_id'])
                ->lockForUpdate()
                ->first();

            if (!$session) {
                throw ValidationException::withMessages([
                    'order_session_id' => ['The specified order session does not exist.'],
                ]);
            }

            // 2. Verify session is OPEN
            if ($session->status !== 'open') {
                throw ValidationException::withMessages([
                    'order_session_id' => ['This order session is not currently open for submissions.'],
                ]);
            }

            // 3. Verify session expiration time
            if ($session->expires_at && Carbon::now()->greaterThan($session->expires_at)) {
                // Automatically mark as expired if time has passed
                $session->update(['status' => 'expired']);

                throw ValidationException::withMessages([
                    'order_session_id' => ['The time window for this order session has expired.'],
                ]);
            }

            // 4. Verify user doesn't already have an order in this session (Enforces unique constraint)
            $existingOrder = Order::where('order_session_id', $session->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existingOrder) {
                throw ValidationException::withMessages([
                    'order' => ['You have already submitted an order for this session.'],
                ]);
            }

            // 5. Generate unique order number
            $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 99999), 5, '0', STR_PAD_LEFT);

            // 6. Create the parent order record
            $order = Order::create([
                'order_number' => $orderNumber,
                'order_session_id' => $session->id,
                'user_id' => $user->id,
                'total_amount' => 0, // Will calculate dynamically
                'status' => 'submitted',
                'submitted_at' => Carbon::now(),
            ]);

            $totalAmount = 0;
            $itemsData = $data['items'];

            // Extract all product IDs to query in bulk
            $productIds = collect($itemsData)->pluck('product_id');
            $products = Product::whereIn('id', $productIds)
                ->where('is_available', true)
                ->get()
                ->keyBy('id');

            foreach ($itemsData as $item) {
                $productId = $item['product_id'];
                $quantity = (int) $item['quantity'];

                if ($quantity <= 0) {
                    continue;
                }

                // Verify product exists and is available
                if (!isset($products[$productId])) {
                    throw ValidationException::withMessages([
                        'items' => ["Product with ID {$productId} is either invalid or currently unavailable."],
                    ]);
                }

                $product = $products[$productId];

                // CRITICAL: Pull unit price strictly from the database, never trust the frontend
                $unitPrice = $product->price;
                $subtotal = $unitPrice * $quantity;
                $totalAmount += $subtotal;

                // Create order item snapshot
                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'unit_price' => $unitPrice,
                    'quantity' => $quantity,
                    'subtotal' => $subtotal,
                ]);
            }

            // Ensure order contains actual items
            if ($order->items()->count() === 0) {
                throw ValidationException::withMessages([
                    'items' => ['Your order must contain at least one valid item.'],
                ]);
            }

            // 7. Update order with calculated total sum
            $order->update(['total_amount' => $totalAmount]);

            return $order->load('items', 'session');
        });
    }
}
