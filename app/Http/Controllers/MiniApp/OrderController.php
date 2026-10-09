<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Models\OrderSession;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_session_id' => ['required', 'exists:order_sessions,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $startParam = $request->attributes->get('telegram_web_app_start_param');
        if ($startParam !== null
            && (!is_string($startParam)
                || !ctype_digit($startParam)
                || (string) $validated['order_session_id'] !== $startParam)) {
            throw ValidationException::withMessages([
                'order_session_id' => ['The selected order session does not match this Telegram Mini App link.'],
            ]);
        }

        $user = $request->user();

        $order = $this->orderService->submitOrder($user, $validated);

        return response()->json([
            'message' => 'Order submitted successfully',
            'data' => $order
        ], 201);
    }

    public function myOrder(Request $request)
    {
        $session = OrderSession::query()->where('status', 'open')->latest('started_at')->first();
        $order = $session
            ? $session->orders()
                ->where('user_id', $request->user()->id)
                ->with('items', 'session')
                ->first()
            : null;

        return response()->json(['data' => $order]);
    }
}
