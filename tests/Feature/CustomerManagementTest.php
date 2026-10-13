<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Product;
use App\Models\TelegramCustomerMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    private int $sessionNumber = 0;

    private int $orderNumber = 0;

    public function test_customer_list_requires_an_admin_and_uses_inclusive_business_timezone_dates(): void
    {
        config(['app.business_timezone' => 'Asia/Bangkok']);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $customer = $this->customer(812345678, [
            'username' => 'case_customer',
            'first_name' => 'Case',
            'last_name' => 'Customer',
            'name' => 'Case Customer',
        ]);
        $this->createOrder($admin, $customer, '2026-05-01 16:59:59', 'submitted', 'Before range', '4.00');
        $this->createOrder($admin, $customer, '2026-05-01 17:00:00', 'submitted', 'First date', '7.50');
        $this->createOrder($admin, $customer, '2026-05-02 12:00:00', 'cancelled', 'Cancelled', '5.00');
        $this->createOrder($admin, $customer, '2026-05-03 16:59:59', 'submitted', 'Last date', '2.50');
        $this->createOrder($admin, $customer, '2026-05-03 17:00:00', 'submitted', 'After range', '10.00');

        $this->getJson(
            '/api/admin/customers?date_from=2026-05-02&date_to=2026-05-03&search=case_customer',
            $this->headers($admin)
        )->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.telegram_user_id', 812345678)
            ->assertJsonPath('data.0.telegram_username', 'case_customer')
            ->assertJsonPath('data.0.order_count', 3)
            ->assertJsonPath('data.0.total_spending', '10.00')
            ->assertJsonPath('filters.timezone', 'Asia/Bangkok')
            ->assertJsonPath('totals_basis.total_spending', 'Submitted orders within the selected date range; cancelled orders are excluded.');

    }

    public function test_customer_admin_routes_require_an_authenticated_admin(): void
    {
        $this->getJson('/api/admin/customers')->assertUnauthorized();
        $nonAdmin = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->getJson('/api/admin/customers', $this->headers($nonAdmin))->assertForbidden();
    }

    public function test_customer_list_defaults_to_today_and_rejects_invalid_date_ranges(): void
    {
        config(['app.business_timezone' => 'Asia/Bangkok']);
        $this->travelTo(Carbon::parse('2026-10-13 05:00:00', 'UTC'));
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $customer = $this->customer(823456789);
        $this->createOrder($admin, $customer, '2026-10-12 16:59:59', 'submitted', 'Yesterday', '1.00');
        $this->createOrder($admin, $customer, '2026-10-12 17:00:00', 'submitted', 'Today', '2.00');

        $this->getJson('/api/admin/customers', $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('filters.date_from', '2026-10-13')
            ->assertJsonPath('filters.date_to', '2026-10-13');

        $this->getJson('/api/admin/customers?date_from=2026-13-45', $this->headers($admin))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_from');
        $this->getJson(
            '/api/admin/customers?date_from=2026-10-14&date_to=2026-10-13',
            $this->headers($admin)
        )->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->travelBack();
    }

    public function test_customer_order_report_returns_historical_item_snapshots_and_supports_filters(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $customer = $this->customer(834567891, [
            'username' => 'snapshot_user',
            'first_name' => 'Snapshot',
            'last_name' => 'Customer',
            'name' => 'Snapshot Customer',
        ]);
        $matching = $this->createOrder(
            $admin,
            $customer,
            '2026-07-02 09:30:00',
            'submitted',
            'Closed July Session',
            '8.50',
            ['product_name' => 'Old Latte', 'unit_price' => '4.25', 'quantity' => 2, 'subtotal' => '8.50'],
            'closed'
        );
        $this->createOrder($admin, $customer, '2026-07-03 09:30:00', 'cancelled', 'Another Session', '3.00');

        Product::create(['name' => 'Old Latte', 'price' => '9.99', 'is_available' => true]);
        $this->getJson(
            "/api/admin/customers/{$customer->id}/orders?date_from=2026-07-02&date_to=2026-07-02&order_session_id={$matching->order_session_id}&status=submitted",
            $this->headers($admin)
        )->assertOk()
            ->assertJsonPath('customer.telegram_user_id', 834567891)
            ->assertJsonPath('customer.telegram_username', 'snapshot_user')
            ->assertJsonPath('orders.total', 1)
            ->assertJsonPath('orders.data.0.id', $matching->id)
            ->assertJsonPath('orders.data.0.order_number', $matching->order_number)
            ->assertJsonPath('orders.data.0.session_name', 'Closed July Session')
            ->assertJsonPath('orders.data.0.session_status', 'closed')
            ->assertJsonPath('orders.data.0.status', 'submitted')
            ->assertJsonPath('orders.data.0.items.0.product_name', 'Old Latte')
            ->assertJsonPath('orders.data.0.items.0.unit_price', '4.25')
            ->assertJsonPath('orders.data.0.items.0.quantity', 2)
            ->assertJsonPath('orders.data.0.items.0.subtotal', '8.50');
    }

    public function test_verified_mini_app_identity_is_unique_and_client_supplied_identity_is_ignored(): void
    {
        $botToken = 'test-customer-bot-token';
        config(['services.telegram.bot_token' => $botToken]);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $product = Product::create([
            'name' => 'Tea',
            'price' => '2.00',
            'is_available' => true,
        ]);

        foreach (['Identity Session One', 'Identity Session Two'] as $index => $title) {
            $session = OrderSession::create([
                'order_number' => 'IDENTITY-SESSION-'.$index,
                'title' => $title,
                'status' => 'open',
                'started_at' => now(),
                'expires_at' => now()->addHour(),
                'created_by' => $admin->id,
            ]);
            $initData = $this->signedInitData($botToken, [
                'auth_date' => (string) time(),
                'user' => json_encode([
                    'id' => 845678912,
                    'first_name' => 'Signed',
                    'last_name' => 'Customer',
                    'username' => 'signed_customer',
                ], JSON_THROW_ON_ERROR),
            ]);

            $this->postJson('/api/mini-app/orders', [
                'order_session_id' => $session->id,
                'telegram_user_id' => 1,
                'display_name' => 'Untrusted Customer',
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ], ['X-Telegram-Init-Data' => $initData])->assertCreated();
        }

        $customer = User::where('telegram_id', 845678912)->firstOrFail();
        $this->assertDatabaseCount('users', 2);
        $this->assertSame(2, $customer->orders()->count());
        $this->assertSame('Signed Customer', $customer->name);
        $this->assertSame('signed_customer', $customer->username);
        $this->assertSame(0, User::where('telegram_id', 1)->count());
    }

    public function test_admin_can_send_private_messages_and_delivery_failures_are_logged_truthfully(): void
    {
        config(['services.telegram.bot_token' => 'test-message-bot-token']);
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = $this->customer(856789123);
        $headers = $this->headers($admin);

        Http::fakeSequence('api.telegram.org/*/sendMessage')
            ->push([
                'ok' => true,
                'result' => ['message_id' => 246],
            ])
            ->push([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Forbidden: bot was blocked by the user',
            ], 403);
        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Your order is ready.',
        ], $headers)->assertOk()
            ->assertJsonPath('delivered', true)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.telegram_message_id', 246)
            ->assertJsonPath('data.admin_id', $admin->id)
            ->assertJsonPath('data.message', 'Your order is ready.');

        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Second attempt.',
        ], $headers)->assertStatus(502)
            ->assertJsonPath('delivered', false)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.telegram_message_id', null)
            ->assertJsonPath('error', 'The customer must open @ahseven_bot in a private chat and press Start before the bot can message them. Ask them to unblock the bot if they have blocked it.');

        $this->assertDatabaseCount('telegram_customer_messages', 2);
        $this->assertSame(
            ['sent', 'failed'],
            TelegramCustomerMessage::query()->orderBy('id')->pluck('status')->all()
        );
        Http::assertSent(fn ($request) => $request['chat_id'] === 856789123);
    }

    public function test_telegram_rate_limits_are_reported_and_include_retry_after(): void
    {
        config(['services.telegram.bot_token' => 'test-message-bot-token']);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $customer = $this->customer(867891234);
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'description' => 'Too Many Requests',
                'parameters' => ['retry_after' => 12],
            ], 429),
        ]);

        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Please wait.',
        ], $this->headers($admin))->assertStatus(429)
            ->assertHeader('Retry-After', '12')
            ->assertJsonPath('delivered', false)
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('error', 'Telegram is rate limiting messages. Please retry in 12 seconds.');
    }

    public function test_customer_deactivation_preserves_orders_and_hides_customer_from_active_list(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = $this->customer(878912345);
        $order = $this->createOrder($admin, $customer, now()->utc()->toDateTimeString());

        $this->deleteJson("/api/admin/customers/{$customer->id}")
            ->assertUnauthorized();
        $this->deleteJson("/api/admin/customers/{$customer->id}", [], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.order_history_preserved', true);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'user_id' => $customer->id]);
        $this->getJson('/api/admin/customers', $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('total', 0);
        $this->getJson('/api/admin/customers?include_inactive=1', $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.is_active', false);
        $this->getJson("/api/admin/customers/{$customer->id}/orders", $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('orders.total', 1);
    }

    private function customer(int $telegramId, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'is_admin' => false,
            'telegram_id' => $telegramId,
            'name' => 'Telegram Customer '.$telegramId,
            'username' => null,
            'first_name' => null,
            'last_name' => null,
        ], $attributes));
    }

    private function createOrder(
        User $admin,
        User $customer,
        string $submittedAt,
        string $status = 'submitted',
        string $sessionTitle = 'Customer Session',
        string $total = '2.50',
        ?array $item = null,
        string $sessionStatus = 'closed'
    ): Order {
        $this->sessionNumber++;
        $this->orderNumber++;
        $session = OrderSession::create([
            'order_number' => 'CUSTOMER-SESSION-'.$this->sessionNumber,
            'title' => $sessionTitle,
            'status' => $sessionStatus,
            'created_by' => $admin->id,
        ]);
        $timestamp = Carbon::parse($submittedAt, 'UTC');
        $order = Order::create([
            'order_number' => 'CUSTOMER-ORDER-'.$this->orderNumber,
            'order_session_id' => $session->id,
            'user_id' => $customer->id,
            'total_amount' => $total,
            'status' => $status,
            'submitted_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $item ??= [
            'product_name' => 'Historical Coffee',
            'unit_price' => $total,
            'quantity' => 1,
            'subtotal' => $total,
        ];
        OrderItem::create(array_merge($item, [
            'order_id' => $order->id,
            'product_id' => null,
        ]));

        return $order;
    }

    private function headers(User $admin): array
    {
        return [
            'Authorization' => 'Bearer '.$admin->createToken('test-admin')->plainTextToken,
        ];
    }

    private function signedInitData(string $botToken, array $params): string
    {
        ksort($params);
        $dataCheckString = collect($params)
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode("\n");
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $params['hash'] = hash_hmac('sha256', $dataCheckString, $secretKey);

        return http_build_query($params);
    }
}
