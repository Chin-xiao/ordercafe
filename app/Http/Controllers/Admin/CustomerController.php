<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TelegramCustomerMessage;
use App\Models\User;
use App\Services\TelegramDeliveryException;
use App\Services\TelegramService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function __construct(private TelegramService $telegramService) {}

    public function index(Request $request)
    {
        $filters = $this->dateFilters($request, [
            'search' => ['sometimes', 'string', 'max:100'],
            'include_inactive' => ['sometimes', 'boolean'],
            'include_without_orders' => ['sometimes', 'boolean'],
        ]);
        $validated = $filters['validated'];
        $query = User::query()->whereNotNull('telegram_id');

        if (! ($validated['include_inactive'] ?? false)) {
            $query->where('is_active', true);
        }

        if (! ($validated['include_without_orders'] ?? false)) {
            $query->whereHas('orders', fn ($orders) => $this->applyDateRange(
                $orders,
                $filters['from'],
                $filters['to_exclusive']
            ));
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $like = '%'.ltrim($search, '@').'%';
            $query->where(function ($customers) use ($like) {
                $customers->whereRaw('CAST(telegram_id AS TEXT) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(username, \'\')) LIKE LOWER(?)', [$like])
                    ->orWhereRaw('LOWER(COALESCE(first_name, \'\')) LIKE LOWER(?)', [$like])
                    ->orWhereRaw('LOWER(COALESCE(last_name, \'\')) LIKE LOWER(?)', [$like])
                    ->orWhereRaw('LOWER(COALESCE(name, \'\')) LIKE LOWER(?)', [$like]);
            });
        }

        $query
            ->withCount(['orders as order_count' => fn ($orders) => $this->applyDateRange(
                $orders,
                $filters['from'],
                $filters['to_exclusive']
            )])
            ->withMax(['orders as latest_order_at' => fn ($orders) => $this->applyDateRange(
                $orders,
                $filters['from'],
                $filters['to_exclusive']
            )], 'submitted_at')
            ->withSum(['orders as total_spending' => function ($orders) use ($filters) {
                $this->applyDateRange($orders, $filters['from'], $filters['to_exclusive']);
                $orders->where('status', 'submitted');
            }], 'total_amount');

        $customers = $query
            ->orderByDesc('latest_order_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (User $customer) => $this->presentCustomerSummary($customer));

        return response()->json(array_merge($customers->toArray(), [
            'filters' => $filters['effective'],
            'totals_basis' => [
                'order_count' => 'All order statuses within the selected date range.',
                'total_spending' => 'Submitted orders within the selected date range; cancelled orders are excluded.',
            ],
        ]));
    }

    public function orders(Request $request, User $customer)
    {
        $filters = $this->dateFilters($request, [
            'order_session_id' => ['sometimes', 'integer', 'exists:order_sessions,id'],
            'status' => ['sometimes', 'string', Rule::in(['submitted', 'cancelled'])],
        ]);
        $validated = $filters['validated'];

        $orders = Order::query()
            ->where('user_id', $customer->id)
            ->with(['items', 'session'])
            ->when(isset($validated['order_session_id']), fn ($query) => $query->where(
                'order_session_id',
                $validated['order_session_id']
            ))
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']));
        $this->applyDateRange($orders, $filters['from'], $filters['to_exclusive']);

        $orders = $orders
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15)
            ->through(fn (Order $order) => $this->presentOrder($order));

        return response()->json([
            'customer' => $this->presentCustomerIdentity($customer),
            'orders' => $orders->toArray(),
            'filters' => $filters['effective'] + [
                'order_session_id' => $validated['order_session_id'] ?? null,
                'status' => $validated['status'] ?? null,
            ],
        ]);
    }

    public function sendMessage(Request $request, User $customer)
    {
        abort_if($customer->telegram_id === null || $customer->is_admin, 404);

        $request->merge([
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:4096', 'not_regex:/^\s*$/u'],
            'idempotency_key' => [
                'required',
                'string',
                'max:191',
                'regex:/^[A-Za-z0-9._:-]+$/',
            ],
        ]);

        $adminId = $request->user()->id;
        $existingDelivery = TelegramCustomerMessage::query()
            ->where('admin_id', $adminId)
            ->where('idempotency_key', $validated['idempotency_key'])
            ->first();

        if ($existingDelivery) {
            return $this->duplicateMessageResponse(
                $existingDelivery,
                $customer,
                $validated['message']
            );
        }

        if ($customer->telegram_private_chat_id === null
            || $customer->telegram_private_chat_activated_at === null) {
            $botUsername = ltrim((string) config('services.telegram.bot_username', 'ahseven_bot'), '@');

            return response()->json([
                'message' => "The customer must open @{$botUsername} in a private chat and press Start before private messages can be delivered.",
                'delivered' => false,
                'error_code' => 'PRIVATE_CHAT_NOT_STARTED',
                'activation_url' => "https://t.me/{$botUsername}?start=order_notifications",
            ], 409);
        }

        $delivery = TelegramCustomerMessage::query()->firstOrCreate(
            [
                'admin_id' => $adminId,
                'idempotency_key' => $validated['idempotency_key'],
            ],
            [
                'customer_id' => $customer->id,
                'message_text' => $validated['message'],
                'status' => 'pending',
            ]
        );

        if (! $delivery->wasRecentlyCreated) {
            return $this->duplicateMessageResponse($delivery, $customer, $validated['message']);
        }

        try {
            $telegramMessageId = $this->telegramService->sendMessage(
                $customer->telegram_private_chat_id,
                $validated['message']
            );
            $delivery->update([
                'status' => 'sent',
                'telegram_message_id' => $telegramMessageId,
                'sent_at' => Carbon::now('UTC'),
            ]);

            return response()->json([
                'message' => 'The private Telegram message was delivered.',
                'delivered' => true,
                'data' => $this->presentMessage($delivery->fresh()),
            ]);
        } catch (TelegramDeliveryException $exception) {
            $delivery->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);
            [$errorCode, $customerMessage, $statusCode] = $this->privateMessageFailure($exception);

            if (in_array($errorCode, ['BOT_BLOCKED_BY_CUSTOMER', 'PRIVATE_CHAT_UNAVAILABLE'], true)) {
                $customer->forceFill([
                    'telegram_private_chat_id' => null,
                    'telegram_private_chat_activated_at' => null,
                ])->save();
            }

            Log::warning('Private Telegram customer message delivery failed.', [
                'customer_id' => $customer->id,
                'admin_id' => $request->user()->id,
                'telegram_error_code' => $exception->telegramErrorCode,
                'error' => $exception->getMessage(),
            ]);

            $response = response()->json([
                'message' => 'Telegram could not deliver the private message.',
                'delivered' => false,
                'error_code' => $errorCode,
                'error' => $customerMessage,
                'data' => $this->presentMessage($delivery->fresh()),
            ], $statusCode);

            if ($exception->telegramErrorCode === 429 && $exception->telegramRetryAfter !== null) {
                $response->header('Retry-After', (string) $exception->telegramRetryAfter);
            }

            return $response;
        }
    }

    public function destroy(User $customer)
    {
        abort_if($customer->telegram_id === null || $customer->is_admin, 404);

        $customer->update(['is_active' => false]);

        return response()->json([
            'message' => 'The customer was removed from the active customer list. Order history was preserved.',
            'data' => [
                'id' => $customer->id,
                'telegram_user_id' => $customer->telegram_id,
                'is_active' => false,
                'order_history_preserved' => true,
            ],
        ]);
    }

    private function dateFilters(Request $request, array $additionalRules = []): array
    {
        $validator = Validator::make($request->all(), [
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ...$additionalRules,
        ]);
        $validator->after(function ($validator) use ($request): void {
            if ($request->filled('date_from')
                && $request->filled('date_to')
                && $request->input('date_to') < $request->input('date_from')) {
                $validator->errors()->add('date_to', 'The date to must be on or after the date from.');
            }
        });
        $validated = $validator->validate();

        $timezone = config('app.business_timezone');
        $today = Carbon::now($timezone)->toDateString();
        $noDatesProvided = ! isset($validated['date_from']) && ! isset($validated['date_to']);
        $fromDate = $validated['date_from'] ?? ($noDatesProvided ? $today : null);
        $toDate = $validated['date_to'] ?? ($noDatesProvided ? $today : null);
        $from = $fromDate
            ? Carbon::parse($fromDate, $timezone)->startOfDay()->utc()
            : null;
        $toExclusive = $toDate
            ? Carbon::parse($toDate, $timezone)->addDay()->startOfDay()->utc()
            : null;

        return [
            'validated' => $validated,
            'from' => $from,
            'to_exclusive' => $toExclusive,
            'effective' => [
                'date_from' => $fromDate,
                'date_to' => $toDate,
                'timezone' => $timezone,
            ],
        ];
    }

    private function applyDateRange(Builder $query, ?Carbon $from, ?Carbon $toExclusive): Builder
    {
        return $query
            ->when($from, fn ($orders) => $orders->where('submitted_at', '>=', $from))
            ->when($toExclusive, fn ($orders) => $orders->where('submitted_at', '<', $toExclusive));
    }

    private function presentCustomerIdentity(User $customer): array
    {
        $privateChatActivated = $customer->telegram_private_chat_id !== null
            && $customer->telegram_private_chat_activated_at !== null;
        $botUsername = ltrim((string) config('services.telegram.bot_username', 'ahseven_bot'), '@');

        return [
            'id' => $customer->id,
            'telegram_user_id' => $customer->telegram_id,
            'telegram_username' => $customer->username,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'display_name' => $customer->name,
            'private_chat_activated' => $privateChatActivated,
            'private_chat_activated_at' => $customer->telegram_private_chat_activated_at,
            'private_chat_activation_url' => $privateChatActivated
                ? null
                : "https://t.me/{$botUsername}?start=order_notifications",
            'telegram_last_seen_at' => $customer->telegram_last_seen_at,
            'is_active' => $customer->is_active,
        ];
    }

    private function presentCustomerSummary(User $customer): array
    {
        return $this->presentCustomerIdentity($customer) + [
            'order_count' => $customer->order_count,
            'latest_order_at' => $customer->latest_order_at,
            'total_spending' => number_format((float) ($customer->total_spending ?? 0), 2, '.', ''),
        ];
    }

    private function presentOrder(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'order_session_id' => $order->order_session_id,
            'session_name' => $order->session?->title,
            'session_status' => $order->session?->status,
            'ordered_at' => $order->submitted_at,
            'total_amount' => $order->total_amount,
            'status' => $order->status,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'unit_price' => $item->unit_price,
                'sugar_percent' => $item->sugar_percent,
                'quantity' => $item->quantity,
                'subtotal' => $item->subtotal,
            ])->values(),
        ];
    }

    private function presentMessage(TelegramCustomerMessage $delivery): array
    {
        return [
            'id' => $delivery->id,
            'customer_id' => $delivery->customer_id,
            'admin_id' => $delivery->admin_id,
            'message' => $delivery->message_text,
            'status' => $delivery->status,
            'telegram_message_id' => $delivery->telegram_message_id,
            'error' => $delivery->error_message,
            'created_at' => $delivery->created_at,
            'sent_at' => $delivery->sent_at,
        ];
    }

    private function duplicateMessageResponse(
        TelegramCustomerMessage $delivery,
        User $customer,
        string $message
    ): JsonResponse {
        if ($delivery->customer_id !== $customer->id || $delivery->message_text !== $message) {
            return response()->json([
                'message' => 'This Idempotency-Key was already used for a different message.',
                'delivered' => false,
                'error_code' => 'IDEMPOTENCY_KEY_REUSED',
            ], 409);
        }

        if ($delivery->status === 'sent') {
            return response()->json([
                'message' => 'The private Telegram message was delivered.',
                'delivered' => true,
                'data' => $this->presentMessage($delivery),
            ]);
        }

        return response()->json([
            'message' => 'This message request was already attempted and will not be sent again. Use a new key only for a deliberate new message.',
            'delivered' => false,
            'error_code' => $delivery->status === 'pending'
                ? 'MESSAGE_REQUEST_PENDING'
                : 'MESSAGE_REQUEST_ALREADY_ATTEMPTED',
            'data' => $this->presentMessage($delivery),
        ], 409);
    }

    private function privateMessageFailure(TelegramDeliveryException $exception): array
    {
        $description = strtolower($exception->telegramDescription ?? '');
        if (str_contains($description, 'bot was blocked')
            || str_contains($description, 'blocked by the user')) {
            return [
                'BOT_BLOCKED_BY_CUSTOMER',
                'The customer blocked @ahseven_bot. They must unblock the bot before Telegram can deliver private messages.',
                403,
            ];
        }

        if (str_contains($description, 'chat not found')) {
            return [
                'PRIVATE_CHAT_UNAVAILABLE',
                'Telegram could not find the customer private chat. Ask them to open @ahseven_bot and press Start again.',
                409,
            ];
        }

        if ($exception->telegramErrorCode === 403) {
            return [
                'TELEGRAM_FORBIDDEN',
                'Telegram refused the private message. The customer may need to open @ahseven_bot and press Start.',
                403,
            ];
        }

        if ($exception->telegramErrorCode === 429) {
            return [
                'TELEGRAM_RATE_LIMITED',
                $exception->telegramRetryAfter === null
                    ? 'Telegram is rate limiting messages. Please wait before trying again.'
                    : "Telegram is rate limiting messages. Please retry in {$exception->telegramRetryAfter} seconds.",
                429,
            ];
        }

        if (str_contains(strtolower($exception->getMessage()), 'bot token is not configured')) {
            return [
                'TELEGRAM_NOT_CONFIGURED',
                'Private Telegram messaging is temporarily unavailable because the bot is not configured.',
                503,
            ];
        }

        if ($exception->telegramErrorCode === null) {
            return [
                'TELEGRAM_NETWORK_ERROR',
                'The Telegram API could not be reached. Please retry later.',
                502,
            ];
        }

        return [
            'TELEGRAM_DELIVERY_FAILED',
            $exception->getMessage(),
            502,
        ];
    }
}
