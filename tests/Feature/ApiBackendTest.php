<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Product;
use App\Models\TelegramGroup;
use App\Models\TelegramMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiBackendTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_routes_require_a_valid_admin_token(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();

        $user = User::factory()->create([
            'is_admin' => false,
            'password' => Hash::make('password'),
        ]);

        $this->postJson('/api/admin/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertForbidden()->assertJsonMissingPath('token');

        $this->postJson('/api/admin/register', [
            'name' => 'Untrusted User',
            'email' => 'untrusted@example.com',
            'password' => 'a-long-password',
        ])->assertUnauthorized();
    }

    public function test_admin_can_create_and_list_products_using_sanctum_token(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $token = $admin->createToken('test-admin')->plainTextToken;
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->postJson('/api/admin/products', [
            'name' => 'Latte',
            'price' => '4.25',
            'is_available' => true,
        ], $headers)->assertCreated()
            ->assertJsonPath('data.name', 'Latte')
            ->assertJsonPath('data.price', '4.25');

        $this->getJson('/api/admin/products', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Latte');
    }

    public function test_admin_category_choices_include_drink_and_snacks(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $headers = [
            'Authorization' => 'Bearer '.$admin->createToken('test-admin')->plainTextToken,
        ];

        $this->getJson('/api/admin/categories', $headers)
            ->assertOk()
            ->assertJsonFragment(['name' => 'Drink'])
            ->assertJsonFragment(['name' => 'Snacks']);
    }

    public function test_mini_app_validates_signed_init_data_and_uses_database_prices(): void
    {
        $botToken = 'test-bot-token';
        config(['services.telegram.bot_token' => $botToken]);

        $admin = User::factory()->create(['is_admin' => true]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-TEST-001',
            'title' => 'Lunch',
            'status' => 'open',
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'created_by' => $admin->id,
        ]);
        $product = Product::create([
            'name' => 'Coffee',
            'price' => '2.50',
            'is_available' => true,
        ]);
        $initData = $this->signedInitData($botToken, [
            'auth_date' => (string) time(),
            'query_id' => 'AAEAAAE',
            'user' => json_encode([
                'id' => 123456789,
                'first_name' => 'Cafe',
                'username' => 'cafe_customer',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->postJson('/api/mini-app/orders', [
            'order_session_id' => $session->id,
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'price' => '0.01',
            ]],
        ], ['X-Telegram-Init-Data' => $initData])
            ->assertCreated()
            ->assertJsonPath('data.total_amount', '7.50');

        $order = Order::with('items', 'user')->firstOrFail();
        $this->assertSame('7.50', $order->total_amount);
        $this->assertSame('2.50', $order->items->first()->unit_price);
        $this->assertSame(123456789, $order->user->telegram_id);
        $this->assertSame('cafe_customer', $order->user->username);
        $this->assertSame('Cafe', $order->user->first_name);
        $this->assertDatabaseHas('users', [
            'telegram_id' => 123456789,
            'email' => 'telegram123456789@users.invalid',
        ]);
        $this->assertArrayNotHasKey('password', $order->user->toArray());
    }

    public function test_mini_app_rejects_expired_or_unsigned_init_data(): void
    {
        config(['services.telegram.bot_token' => 'test-bot-token']);

        $this->getJson('/api/mini-app/products', [
            'X-Telegram-Init-Data' => 'auth_date='.(time() - 90000).'&user=%7B%22id%22%3A1%7D&hash='.str_repeat('a', 64),
        ])->assertUnauthorized();
    }

    public function test_mini_app_resolves_the_session_from_signed_telegram_start_param(): void
    {
        $botToken = 'test-bot-token';
        config(['services.telegram.bot_token' => $botToken]);

        $admin = User::factory()->create(['is_admin' => true]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-TEST-005',
            'title' => 'Mini App Lunch',
            'status' => 'open',
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'created_by' => $admin->id,
        ]);
        $initData = $this->signedInitData($botToken, [
            'auth_date' => (string) time(),
            'start_param' => (string) $session->id,
            'user' => json_encode(['id' => 12345678, 'first_name' => 'Test'], JSON_THROW_ON_ERROR),
        ]);

        $this->getJson('/api/mini-app/order-session/current', [
            'X-Telegram-Init-Data' => $initData,
        ])->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.can_order', true);
    }

    public function test_cors_allows_the_configured_render_frontend_origin(): void
    {
        $this->call('OPTIONS', '/api/admin/products', server: [
            'HTTP_ORIGIN' => 'https://ordercafe-front.onrender.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertNoContent()
            ->assertHeader('access-control-allow-origin', 'https://ordercafe-front.onrender.com');
    }

    public function test_admin_can_search_and_view_orders_and_session_summary(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = User::factory()->create([
            'name' => 'Taylor Customer',
            'telegram_id' => 456789123,
            'username' => 'taylor_cafe',
            'first_name' => 'Taylor',
            'last_name' => 'Customer',
        ]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-TEST-006',
            'title' => 'Afternoon Order',
            'status' => 'closed',
            'created_by' => $admin->id,
        ]);
        $order = Order::create([
            'order_number' => 'ORD-SEARCH-001',
            'order_session_id' => $session->id,
            'user_id' => $customer->id,
            'total_amount' => '8.00',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_name' => 'Matcha Latte',
            'unit_price' => '4.00',
            'quantity' => 2,
            'subtotal' => '8.00',
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$admin->createToken('test-admin')->plainTextToken,
        ];

        $this->getJson('/api/admin/orders?search=taylor_cafe&order_session_id='.$session->id, $headers)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.order_number', 'ORD-SEARCH-001')
            ->assertJsonPath('data.0.telegram_username', '@taylor_cafe')
            ->assertJsonPath('data.0.telegram_user_id', 456789123)
            ->assertJsonPath('data.0.session_name', 'Afternoon Order')
            ->assertJsonPath('data.0.items.0.quantity', 2);

        $this->getJson('/api/admin/order-sessions/'.$session->id.'/orders?search=456789123', $headers)
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->getJson('/api/admin/orders/'.$order->id, $headers)
            ->assertOk()
            ->assertJsonPath('data.items.0.product_name', 'Matcha Latte')
            ->assertJsonMissingPath('data.user.email');

        $this->getJson('/api/admin/order-sessions/'.$session->id.'/summary', $headers)
            ->assertOk()
            ->assertJsonPath('data.total_customers', 1)
            ->assertJsonPath('data.total_orders', 1)
            ->assertJsonPath('data.total_revenue', '8.00')
            ->assertJsonPath('data.product_summary.0.total_quantity', 2);
    }

    public function test_starting_and_closing_a_session_notifies_verified_telegram_groups(): void
    {
        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.mini_app_url' => 'https://t.me/ordercafe_bot/order',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 987],
        ])]);

        $admin = User::factory()->create(['is_admin' => true]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-TEST-002',
            'title' => 'Dinner',
            'expires_at' => now()->addHour(),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        $customer = User::factory()->create(['name' => 'Cafe Customer']);
        $order = Order::create([
            'order_number' => 'ORD-TEST-001',
            'order_session_id' => $session->id,
            'user_id' => $customer->id,
            'total_amount' => '12.50',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        OrderItem::create([
            'order_id' => $order->id,
            'product_name' => 'Iced Coffee',
            'unit_price' => '2.50',
            'quantity' => 5,
            'subtotal' => '12.50',
        ]);
        TelegramGroup::create([
            'telegram_chat_id' => -100123456789,
            'title' => 'Cafe Group',
            'group_name' => 'Cafe Group',
            'verify_token' => 'CAFE-TEST-TOKEN',
            'is_verified' => true,
            'is_active' => true,
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$admin->createToken('test-admin')->plainTextToken,
        ];

        $this->postJson("/api/admin/order-sessions/{$session->id}/start", [], $headers)
            ->assertOk()
            ->assertJsonPath('session_started', true)
            ->assertJsonPath('announcement_sent', true)
            ->assertJsonPath('data.status', 'open');
        $this->postJson("/api/admin/order-sessions/{$session->id}/start", [], $headers)
            ->assertUnprocessable();
        $this->postJson("/api/admin/order-sessions/{$session->id}/close", [], $headers)
            ->assertOk()
            ->assertJsonPath('session_closed', true)
            ->assertJsonPath('summary_sent', true)
            ->assertJsonPath('summary.total_orders', 1)
            ->assertJsonPath('summary.total_customers', 1)
            ->assertJsonPath('summary.total_revenue', '12.50')
            ->assertJsonPath('summary.product_summary.0.total_quantity', 5);

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => $request['chat_id'] === -100123456789
            && str_contains(
                $request['reply_markup']['inline_keyboard'][0][0]['url'] ?? '',
                'startapp='.$session->id
            ));
        Http::assertSent(fn (HttpRequest $request) => $request['chat_id'] === -100123456789
            && str_contains($request['text'], 'Iced Coffee × 5')
            && str_contains($request['text'], 'Total amount: $12.50'));
        $this->assertDatabaseHas('telegram_messages', [
            'order_session_id' => $session->id,
            'telegram_chat_id' => -100123456789,
            'telegram_message_id' => 987,
            'status' => 'sent',
        ]);
        $this->assertSame(2, TelegramMessage::where('order_session_id', $session->id)->count());
    }

    public function test_starting_a_session_reports_invalid_mini_app_link_without_rolling_back_session(): void
    {
        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.mini_app_url' => 'https://ordercafe-front.onrender.com/app',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-TEST-003',
            'title' => 'Breakfast',
            'expires_at' => now()->addHour(),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        TelegramGroup::create([
            'telegram_chat_id' => -100123456789,
            'title' => 'Cafe Group',
            'group_name' => 'Cafe Group',
            'verify_token' => 'CAFE-TEST-TOKEN-2',
            'is_verified' => true,
            'is_active' => true,
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$admin->createToken('test-admin')->plainTextToken,
        ];

        $this->postJson("/api/admin/order-sessions/{$session->id}/start", [], $headers)
            ->assertStatus(502)
            ->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('order_sessions', [
            'id' => $session->id,
            'status' => 'open',
        ]);
    }

    public function test_expiration_command_and_submission_reject_orders_after_deadline(): void
    {
        config(['services.telegram.bot_token' => 'test-bot-token']);

        $admin = User::factory()->create(['is_admin' => true]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-TEST-004',
            'title' => 'Expired Lunch',
            'status' => 'open',
            'started_at' => now()->subHour(),
            'expires_at' => now()->subSecond(),
            'created_by' => $admin->id,
        ]);

        Artisan::call('order-sessions:expire');
        $this->assertDatabaseHas('order_sessions', [
            'id' => $session->id,
            'status' => 'expired',
        ]);

        $session->update(['status' => 'open']);
        $product = Product::create([
            'name' => 'Tea',
            'price' => '2.00',
            'is_available' => true,
        ]);
        $initData = $this->signedInitData('test-bot-token', [
            'auth_date' => (string) time(),
            'query_id' => 'AAEAAAE',
            'user' => json_encode(['id' => 7654321, 'first_name' => 'Test'], JSON_THROW_ON_ERROR),
        ]);

        $this->postJson('/api/mini-app/orders', [
            'order_session_id' => $session->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ['X-Telegram-Init-Data' => $initData])->assertUnprocessable();

        $this->assertDatabaseHas('order_sessions', [
            'id' => $session->id,
            'status' => 'expired',
        ]);
        $this->assertDatabaseCount('orders', 0);
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
