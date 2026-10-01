<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderSession;
use App\Services\OrderSessionService;
use Illuminate\Http\Request;

class OrderSessionController extends Controller
{
    protected OrderSessionService $sessionService;

    public function __construct(OrderSessionService $sessionService)
    {
        $this->sessionService = $sessionService;
    }

    public function index()
    {
        $sessions = OrderSession::with('creator', 'closer')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($sessions);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $session = $this->sessionService->createSession($request->user(), $request->all());

        return response()->json([
            'message' => 'Order session created successfully as draft.',
            'data' => $session
        ], 201);
    }

    public function show(OrderSession $orderSession)
    {
        return response()->json([
            'data' => $orderSession->load('creator', 'closer', 'orders.user', 'orders.items')
        ]);
    }

    public function start(OrderSession $orderSession)
    {
        $session = $this->sessionService->startSession($orderSession);

        return response()->json([
            'message' => 'Order session is now open!',
            'data' => $session
        ]);
    }

    public function close(Request $request, OrderSession $orderSession)
    {
        $summary = $this->sessionService->closeSession($orderSession, $request->user());

        return response()->json([
            'message' => 'Order session closed successfully.',
            'summary' => $summary
        ]);
    }
}
