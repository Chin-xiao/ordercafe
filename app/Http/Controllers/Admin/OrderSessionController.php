<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrderSession;
use App\Services\OrderSessionService;
use App\Services\TelegramDeliveryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

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
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'scheduled_start_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
            'announcement_message' => ['nullable', 'string', 'max:3000'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            $hasExpiration = $request->filled('expires_at');
            $hasDuration = $request->filled('duration_minutes');

            if ($hasExpiration === $hasDuration) {
                $validator->errors()->add(
                    'expires_at',
                    'Provide either an expiration date/time or a duration in minutes.'
                );
            }

            if ($hasExpiration && ! $validator->errors()->has('expires_at')) {
                $expiresAt = Carbon::parse(
                    $request->input('expires_at'),
                    config('app.business_timezone')
                )->utc();

                if ($expiresAt->lessThanOrEqualTo(now('UTC'))) {
                    $validator->errors()->add('expires_at', 'The expiration must be in the future.');
                }
            }
        });
        $validated = $validator->validate();

        foreach (['scheduled_start_at', 'expires_at'] as $dateField) {
            if (! empty($validated[$dateField])) {
                $validated[$dateField] = Carbon::parse(
                    $validated[$dateField],
                    config('app.business_timezone')
                )->utc();
            }
        }

        $session = $this->sessionService->createSession($request->user(), $validated);

        return response()->json([
            'message' => 'Order session created successfully as draft.',
            'data' => $session,
        ], 201);
    }

    public function show(OrderSession $orderSession)
    {
        return response()->json([
            'data' => $orderSession->load([
                'creator:id,name',
                'closer:id,name',
                'orders.user:id,telegram_id,username,first_name,last_name',
                'orders.items',
            ]),
        ]);
    }

    public function summary(OrderSession $orderSession)
    {
        return response()->json([
            'data' => $this->sessionService->sessionSummary($orderSession),
        ]);
    }

    public function start(OrderSession $orderSession)
    {
        try {
            $result = $this->sessionService->startSession($orderSession);
        } catch (TelegramDeliveryException $exception) {
            return response()->json([
                'message' => 'The session started, but the Telegram announcement could not be delivered.',
                'session_started' => true,
                'announcement_sent' => false,
                'error' => $exception->getMessage(),
                'data' => $exception->sessionData['session'] ?? null,
                'telegram_notifications' => $exception->sessionData['notifications'] ?? [],
            ], 502);
        }

        return response()->json([
            'message' => 'Order session is now open and the Telegram announcement was sent.',
            'session_started' => true,
            'announcement_sent' => true,
            'data' => $result['session'],
            'telegram_notifications' => $result['notifications'],
        ]);
    }

    public function close(Request $request, OrderSession $orderSession)
    {
        try {
            $result = $this->sessionService->closeSession($orderSession, $request->user());
        } catch (TelegramDeliveryException $exception) {
            return response()->json([
                'message' => 'The session is closed, but its Telegram summary could not be delivered.',
                'session_closed' => true,
                'summary_sent' => false,
                'error' => $exception->getMessage(),
                'summary' => $exception->sessionData['summary'] ?? null,
                'telegram_notifications' => $exception->sessionData['notifications'] ?? [],
            ], 502);
        }

        return response()->json([
            'message' => 'Order session closed and the Telegram summary was sent.',
            'session_closed' => true,
            'summary_sent' => true,
            'summary' => $result['summary'],
            'telegram_notifications' => $result['notifications'],
        ]);
    }
}
