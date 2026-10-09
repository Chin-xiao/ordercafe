<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        return $this->orders($request);
    }

    public function forSession(Request $request, OrderSession $orderSession)
    {
        $request->merge(['order_session_id' => $orderSession->id]);

        return $this->orders($request);
    }

    public function show(Order $order)
    {
        $order->load(['user', 'session', 'items']);

        return response()->json(['data' => $this->presentOrder($order)]);
    }

    private function orders(Request $request)
    {
        $validated = Validator::make($request->all(), [
            'order_session_id' => ['sometimes', 'integer', 'exists:order_sessions,id'],
            'status' => ['sometimes', 'string', Rule::in(['submitted', 'cancelled'])],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();

        $from = isset($validated['date_from'])
            ? Carbon::parse($validated['date_from'], config('app.business_timezone'))->startOfDay()->utc()
            : null;
        $toExclusive = isset($validated['date_to'])
            ? Carbon::parse($validated['date_to'], config('app.business_timezone'))->addDay()->startOfDay()->utc()
            : null;

        $query = Order::query()
            ->with(['user', 'session', 'items'])
            ->when(isset($validated['order_session_id']), fn ($query) => $query->where(
                'order_session_id',
                $validated['order_session_id']
            ))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->when($from, fn ($query) => $query->where('submitted_at', '>=', $from))
            ->when($toExclusive, fn ($query) => $query->where('submitted_at', '<', $toExclusive));

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $like = '%'.$search.'%';

            $query->where(function ($query) use ($like) {
                $query->whereRaw('LOWER(order_number) LIKE LOWER(?)', [$like])
                    ->orWhereHas('user', function ($userQuery) use ($like) {
                        $userQuery->whereRaw('LOWER(username) LIKE LOWER(?)', [$like])
                            ->orWhereRaw('LOWER(first_name) LIKE LOWER(?)', [$like])
                            ->orWhereRaw('LOWER(last_name) LIKE LOWER(?)', [$like])
                            ->orWhereRaw('LOWER(name) LIKE LOWER(?)', [$like])
                            ->orWhereRaw('CAST(telegram_id AS TEXT) LIKE ?', [$like]);
                    });
            });
        }

        $orders = $query
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Order $order) => $this->presentOrder($order));

        return response()->json($orders);
    }

    private function presentOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_session_id' => $order->order_session_id,
            'session_name' => $order->session?->title,
            'telegram_user_id' => $order->user?->telegram_id,
            'telegram_username' => $order->user?->username
                ? '@'.ltrim($order->user->username, '@')
                : null,
            'first_name' => $order->user?->first_name,
            'last_name' => $order->user?->last_name,
            'ordered_at' => $order->submitted_at,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'items' => $order->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'subtotal' => $item->subtotal,
            ])->values(),
        ];
    }
}
