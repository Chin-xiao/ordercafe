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
use Illuminate\Support\Carbon;
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
        $this->getJson('/api/admin/orders')->assertUnauthorized();

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
            'start_param' => 'order_'.$session->id,
            'user' => json_encode(['id' => 12345678, 'first_name' => 'Test'], JSON_THROW_ON_ERROR),
        ]);

        $this->getJson('/api/mini-app/order-session/current', [
            'X-Telegram-Init-Data' => $initData,
        ])->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.ordering_allowed', true)
            ->assertJsonPath('data.can_order', true);

        $invalidInitData = $this->signedInitData($botToken, [
            'auth_date' => (string) time(),
            'start_param' => 'order_not-a-session',
            'user' => json_encode(['id' => 12345678], JSON_THROW_ON_ERROR),
        ]);
        $this->getJson('/api/mini-app/order-session/current', [
            'X-Telegram-Init-Data' => $invalidInitData,
        ])->assertOk()->assertJsonPath('data', null);
    }

    public function test_mini_app_cannot_submit_an_order_for_a_different_session_than_signed_start_param(): void
    {
        $botToken = 'test-bot-token';
        config(['services.telegram.bot_token' => $botToken]);

        $admin = User::factory()->create(['is_admin' => true]);
        $linkedSession = OrderSession::create([
            'order_number' => 'SESSION-TEST-LINKED',
            'title' => 'Linked Session',
            'status' => 'open',
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'created_by' => $admin->id,
        ]);
        $otherSession = OrderSession::create([
            'order_number' => 'SESSION-TEST-OTHER',
            'title' => 'Other Session',
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
            'start_param' => 'order_'.$linkedSession->id,
            'user' => json_encode(['id' => 12345678], JSON_THROW_ON_ERROR),
        ]);

        $this->postJson('/api/mini-app/orders', [
            'order_session_id' => $linkedSession->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ['X-Telegram-Init-Data' => $initData])
            ->assertCreated()
            ->assertJsonPath('data.order_session_id', $linkedSession->id);

        $this->postJson('/api/mini-app/orders', [
            'order_session_id' => $otherSession->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], ['X-Telegram-Init-Data' => $initData])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order_session_id');

        $this->assertDatabaseCount('orders', 1);
    }

    public function test_mini_app_does_not_resolve_closed_or_expired_sessions_from_start_param(): void
    {
        $botToken = 'test-bot-token';
        config(['services.telegram.bot_token' => $botToken]);

        $admin = User::factory()->create(['is_admin' => true]);
        $closedSession = OrderSession::create([
            'order_number' => 'SESSION-TEST-CLOSED',
            'title' => 'Closed Session',
            'status' => 'closed',
            'expires_at' => now()->addHour(),
            'created_by' => $admin->id,
        ]);
        $expiredSession = OrderSession::create([
            'order_number' => 'SESSION-TEST-EXPIRED',
            'title' => 'Expired Session',
            'status' => 'open',
            'expires_at' => now()->subSecond(),
            'created_by' => $admin->id,
        ]);

        foreach ([[$closedSession, 'closed'], [$expiredSession, 'expired']] as [$session, $expectedStatus]) {
            $initData = $this->signedInitData($botToken, [
                'auth_date' => (string) time(),
                'start_param' => 'order_'.$session->id,
                'user' => json_encode(['id' => 12345678], JSON_THROW_ON_ERROR),
            ]);

            $this->getJson('/api/mini-app/order-session/current', [
                'X-Telegram-Init-Data' => $initData,
            ])->assertOk()
                ->assertJsonPath('data.id', $session->id)
                ->assertJsonPath('data.status', $expectedStatus)
                ->assertJsonPath('data.ordering_allowed', false);
        }

        $this->assertDatabaseHas('order_sessions', [
            'id' => $expiredSession->id,
            'status' => 'expired',
        ]);
    }

    public function test_mini_app_cannot_submit_orders_for_closed_or_expired_sessions(): void
    {
        $botToken = 'test-bot-token';
        config(['services.telegram.bot_token' => $botToken]);

        $admin = User::factory()->create(['is_admin' => true]);
        $product = Product::create([
            'name' => 'Coffee',
            'price' => '2.50',
            'is_available' => true,
        ]);
        $sessions = [
            OrderSession::create([
                'order_number' => 'SESSION-TEST-CLOSED-ORDER',
                'title' => 'Closed Session',
                'status' => 'closed',
                'expires_at' => now()->addHour(),
                'created_by' => $admin->id,
            ]),
            OrderSession::create([
                'order_number' => 'SESSION-TEST-EXPIRED-ORDER',
                'title' => 'Expired Session',
                'status' => 'open',
                'expires_at' => now()->subSecond(),
                'created_by' => $admin->id,
            ]),
        ];

        foreach ($sessions as $session) {
            $initData = $this->signedInitData($botToken, [
                'auth_date' => (string) time(),
                'start_param' => 'order_'.$session->id,
                'user' => json_encode(['id' => 12345678], JSON_THROW_ON_ERROR),
            ]);

            $this->postJson('/api/mini-app/orders', [
                'order_session_id' => $session->id,
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
            ], ['X-Telegram-Init-Data' => $initData])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('order_session_id');
        }

        $this->assertDatabaseCount('orders', 0);
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
            ->assertJsonPath('data.0.customer_name', 'Taylor Customer')
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

    public function test_same_telegram_customer_can_order_again_and_date_range_is_inclusive_in_business_timezone(): void
    {
        $botToken = 'test-bot-token';
        config([
            'services.telegram.bot_token' => $botToken,
            'app.business_timezone' => 'Asia/Bangkok',
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $sessions = collect(range(7, 10))->map(fn (int $number) => OrderSession::create([
            'order_number' => "SESSION-TEST-00{$number}",
            'title' => "Session {$number}",
            'status' => 'draft',
            'created_by' => $admin->id,
        ]));
        $product = Product::create([
            'name' => 'Coffee',
            'price' => '3.00',
            'is_available' => true,
        ]);

        $submitOrder = function (OrderSession $session, int $telegramId, string $name, ?string $username, string $timestamp) use ($botToken, $product): int {
            $this->travelTo(Carbon::parse($timestamp, 'UTC'));
            $session->update(['status' => 'open', 'expires_at' => now()->addDay()]);
            $initData = $this->signedInitData($botToken, [
                'auth_date' => (string) time(),
                'user' => json_encode(array_filter([
                    'id' => $telegramId,
                    'first_name' => $name,
                    'username' => $username,
                ], fn ($value) => $value !== null), JSON_THROW_ON_ERROR),
            ]);

            $response = $this->postJson('/api/mini-app/orders', [
                'order_session_id' => $session->id,
                'items' => [['product_id' => $product->id, 'quantity' => 2]],
            ], ['X-Telegram-Init-Data' => $initData])->assertCreated();

            $orderId = $response->json('data.id');
            Order::whereKey($orderId)->update([
                'created_at' => Carbon::parse($timestamp, 'UTC'),
                'submitted_at' => Carbon::parse($timestamp, 'UTC'),
            ]);

            return $orderId;
        };

        $beforeRange = $submitOrder(
            $sessions[0],
            987654321,
            'First Name',
            'first_handle',
            '2026-10-08 16:59:59'
        );
        $firstDayBoundary = $submitOrder(
            $sessions[1],
            987654321,
            'Updated Name',
            'updated_handle',
            '2026-10-08 17:00:00'
        );
        $lastDayBoundary = $submitOrder(
            $sessions[2],
            987654321,
            'Updated Name',
            'updated_handle',
            '2026-10-09 16:59:59'
        );
        $afterRange = $submitOrder(
            $sessions[3],
            987654321,
            'Updated Name',
            'updated_handle',
            '2026-10-09 17:00:00'
        );
        $this->travelBack();

        $this->assertSame(1, User::where('telegram_id', 987654321)->count());
        $this->assertSame(4, Order::whereIn('id', [
            $beforeRange,
            $firstDayBoundary,
            $lastDayBoundary,
            $afterRange,
        ])->where('user_id', User::where('telegram_id', 987654321)->value('id'))->count());
        $this->assertDatabaseHas('users', [
            'telegram_id' => 987654321,
            'name' => 'Updated Name',
            'username' => 'updated_handle',
        ]);

        $adminHeaders = [
            'Authorization' => 'Bearer '.$admin->createToken('test-admin')->plainTextToken,
        ];
        $this->getJson('/api/admin/orders?date_from=2026-10-09&date_to=2026-10-09&search=987654321&per_page=1', $adminHeaders)
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('summary.total_orders', 2)
            ->assertJsonPath('summary.total_customers', 1)
            ->assertJsonPath('summary.total_sales', '12.00')
            ->assertJsonPath('summary.total_items', 4)
            ->assertJsonPath('data.0.telegram_user_id', 987654321)
            ->assertJsonPath('data.0.telegram_username', '@updated_handle')
            ->assertJsonPath('data.0.customer_name', 'Updated Name')
            ->assertJsonPath('data.0.first_name', 'Updated Name');
    }

    public function test_starting_and_closing_a_session_notifies_verified_telegram_groups(): void
    {
        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.mini_app_url' => 'https://t.me/ordercafe_bot/order',
        ]);
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => ['username' => 'ordercafe_bot'],
            ]),
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 987],
            ]),
        ]);

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

        Http::assertSentCount(3);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === -100123456789
            && $request['text'] === "តោះមេៗអាច Order Drink អីឡូវ​បាន\n\nPlease Click <b>Order Now</b>"
            && $request['parse_mode'] === 'HTML'
            && ($request['reply_markup']['inline_keyboard'][0][0] ?? null) === [
                'text' => '🛒 Order Now',
                'url' => "https://t.me/ordercafe_bot/order?startapp=order_{$session->id}",
            ]);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === -100123456789
            && str_contains($request['text'], 'Iced Coffee x5 Unit price: 2.5៛; Line total: 12.5៛')
            && str_contains($request['text'], '<b>Total Price: 12.5៛</b>')
            && str_contains($request['text'], 'Status: closed'));
        $this->assertDatabaseHas('telegram_messages', [
            'order_session_id' => $session->id,
            'telegram_chat_id' => -100123456789,
            'telegram_message_id' => 987,
            'status' => 'sent',
        ]);
        $this->assertSame(2, TelegramMessage::where('order_session_id', $session->id)->count());
    }

    public function test_starting_a_session_does_not_post_a_main_app_link_when_bot_has_no_main_mini_app(): void
    {
        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.mini_app_url' => 'https://t.me/ordercafe_bot',
        ]);
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => [
                    'username' => 'ordercafe_bot',
                    'has_main_web_app' => false,
                ],
            ]),
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 989],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-TEST-NO-MAIN-APP',
            'title' => 'Lunch',
            'expires_at' => now()->addHour(),
            'status' => 'draft',
            'created_by' => $admin->id,
        ]);
        TelegramGroup::create([
            'telegram_chat_id' => -100123456789,
            'title' => 'Cafe Group',
            'group_name' => 'Cafe Group',
            'verify_token' => 'CAFE-TEST-TOKEN-3',
            'is_verified' => true,
            'is_active' => true,
        ]);
        $headers = [
            'Authorization' => 'Bearer '.$admin->createToken('test-admin')->plainTextToken,
        ];

        $this->postJson("/api/admin/order-sessions/{$session->id}/start", [], $headers)
            ->assertStatus(502)
            ->assertJsonPath('announcement_sent', false)
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath(
                'error',
                'The Telegram bot has no configured Main Mini App. Configure it in BotFather before using the Main Mini App group link.'
            );

        Http::assertSentCount(1);
        $this->assertDatabaseHas('telegram_messages', [
            'order_session_id' => $session->id,
            'message_type' => 'session_opened',
            'status' => 'failed',
        ]);
    }

    public function test_starting_a_session_reports_invalid_mini_app_link_without_rolling_back_session(): void
    {
        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.mini_app_url' => 'https://ordercafe-front.onrender.com/app',
        ]);
        Http::fake([
            'api.telegram.org/*/getMe' => Http::response([
                'ok' => true,
                'result' => [
                    'username' => 'ordercafe_bot',
                    'has_main_web_app' => true,
                ],
            ]),
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 988],
            ]),
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
        $this->assertDatabaseHas('telegram_messages', [
            'order_session_id' => $session->id,
            'message_type' => 'session_opened',
            'status' => 'failed',
        ]);

        config(['services.telegram.mini_app_url' => 'https://t.me/ordercafe_bot']);
        $this->postJson("/api/admin/order-sessions/{$session->id}/announcement/retry", [], $headers)
            ->assertOk()
            ->assertJsonPath('session_started', true)
            ->assertJsonPath('announcement_sent', true);

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => (
            $request['reply_markup']['inline_keyboard'][0][0]['url'] ?? ''
        ) === "https://t.me/ordercafe_bot?startapp=order_{$session->id}");
        $this->assertDatabaseHas('telegram_messages', [
            'order_session_id' => $session->id,
            'telegram_message_id' => 988,
            'status' => 'sent',
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

    public function test_order_items_store_independent_sugar_percentages_without_changing_database_prices(): void
    {
        $botToken = 'test-bot-token';
        config(['services.telegram.bot_token' => $botToken]);

        $admin = User::factory()->create(['is_admin' => true]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-SUGAR-001',
            'title' => 'Sugar Options',
            'status' => 'open',
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'created_by' => $admin->id,
        ]);
        $latte = Product::create(['name' => 'Ice Latte', 'price' => '5000.00', 'is_available' => true]);
        $americano = Product::create(['name' => 'Americano', 'price' => '4000.00', 'is_available' => true]);
        $initData = $this->signedInitData($botToken, [
            'auth_date' => (string) time(),
            'user' => json_encode(['id' => 7654001, 'first_name' => 'Dara'], JSON_THROW_ON_ERROR),
        ]);

        $this->postJson('/api/mini-app/orders', [
            'order_session_id' => $session->id,
            'items' => [[
                'product_id' => $latte->id,
                'quantity' => 1,
                'sugar_percent' => 10,
            ]],
        ], ['X-Telegram-Init-Data' => $initData])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.sugar_percent');
        $this->assertDatabaseCount('orders', 0);

        $response = $this->postJson('/api/mini-app/orders', [
            'order_session_id' => $session->id,
            'items' => [
                ['product_id' => $latte->id, 'quantity' => 1, 'sugar_percent' => 50, 'price' => '0.01'],
                ['product_id' => $latte->id, 'quantity' => 2, 'sugar_percent' => 75, 'price' => '0.01'],
                ['product_id' => $americano->id, 'quantity' => 1, 'sugar_percent' => 0],
                ['product_id' => $americano->id, 'quantity' => 1, 'price' => '0.01'],
            ],
        ], ['X-Telegram-Init-Data' => $initData])->assertCreated()
            ->assertJsonPath('data.total_amount', '23000.00');

        $items = $response->json('data.items');
        $this->assertSame([50, 75, 0, null], array_column($items, 'sugar_percent'));
        $this->assertSame(['5000.00', '5000.00', '4000.00', '4000.00'], array_column($items, 'unit_price'));
        $this->assertSame(4, OrderItem::count());
    }

    public function test_admin_daily_order_report_uses_business_dates_and_keeps_orders_separate_from_items(): void
    {
        config(['app.business_timezone' => 'Asia/Bangkok']);
        $this->travelTo(Carbon::parse('2026-10-16 01:00:00', 'UTC'));

        $admin = User::factory()->create(['is_admin' => true]);
        $firstCustomer = User::factory()->create([
            'name' => 'Sok Dara',
            'telegram_id' => 987654321,
            'username' => 'sok_dara',
            'first_name' => 'Sok',
            'last_name' => 'Dara',
        ]);
        $secondCustomer = User::factory()->create([
            'name' => 'Chan Srey',
            'telegram_id' => 987654322,
            'username' => 'chan_srey',
            'first_name' => 'Chan',
            'last_name' => 'Srey',
        ]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-REPORT-001',
            'title' => 'Morning Drinks',
            'status' => 'closed',
            'created_by' => $admin->id,
        ]);
        $firstOrder = Order::create([
            'order_number' => 'ORD-REPORT-001',
            'order_session_id' => $session->id,
            'user_id' => $firstCustomer->id,
            'total_amount' => '14000.00',
            'status' => 'submitted',
            'submitted_at' => Carbon::parse('2026-10-15 17:00:00', 'UTC'),
        ]);
        OrderItem::create([
            'order_id' => $firstOrder->id,
            'product_name' => 'Ice Latte',
            'unit_price' => '5000.00',
            'quantity' => 1,
            'subtotal' => '5000.00',
            'sugar_percent' => 50,
        ]);
        OrderItem::create([
            'order_id' => $firstOrder->id,
            'product_name' => 'Ice Latte',
            'unit_price' => '5000.00',
            'quantity' => 1,
            'subtotal' => '5000.00',
            'sugar_percent' => 50,
        ]);
        OrderItem::create([
            'order_id' => $firstOrder->id,
            'product_name' => 'Americano',
            'unit_price' => '4000.00',
            'quantity' => 1,
            'subtotal' => '4000.00',
        ]);

        $secondOrder = Order::create([
            'order_number' => 'ORD-REPORT-002',
            'order_session_id' => $session->id,
            'user_id' => $secondCustomer->id,
            'total_amount' => '4000.00',
            'status' => 'submitted',
            'submitted_at' => Carbon::parse('2026-10-15 18:00:00', 'UTC'),
        ]);
        OrderItem::create([
            'order_id' => $secondOrder->id,
            'product_name' => 'Americano',
            'unit_price' => '4000.00',
            'quantity' => 1,
            'subtotal' => '4000.00',
            'sugar_percent' => 0,
        ]);

        Order::create([
            'order_number' => 'ORD-REPORT-CANCELLED',
            'order_session_id' => $session->id,
            'user_id' => User::factory()->create()->id,
            'total_amount' => '5000.00',
            'status' => 'cancelled',
            'submitted_at' => Carbon::parse('2026-10-15 18:30:00', 'UTC'),
        ]);
        $outsideToday = Order::create([
            'order_number' => 'ORD-REPORT-OLD-DATE',
            'order_session_id' => $session->id,
            'user_id' => User::factory()->create()->id,
            'total_amount' => '1000.00',
            'status' => 'submitted',
            'submitted_at' => Carbon::parse('2026-10-15 16:59:59', 'UTC'),
        ]);
        OrderItem::create([
            'order_id' => $outsideToday->id,
            'product_name' => 'Yesterday Drink',
            'unit_price' => '1000.00',
            'quantity' => 1,
            'subtotal' => '1000.00',
        ]);

        $headers = [
            'Authorization' => 'Bearer '.$admin->createToken('test-admin')->plainTextToken,
        ];
        $this->getJson('/api/admin/reports/daily-orders')->assertUnauthorized();

        $this->getJson('/api/admin/reports/daily-orders?per_page=1', $headers)
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('filters.date_from', '2026-10-16')
            ->assertJsonPath('filters.date_to', '2026-10-16')
            ->assertJsonPath('filters.timezone', 'Asia/Bangkok');

        $this->getJson('/api/admin/reports/daily-orders?search=987654321&date_from=2026-10-16&date_to=2026-10-16&order_session_id='.$session->id, $headers)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.customer.display_name', 'Sok Dara')
            ->assertJsonPath('data.0.customer.telegram_username', '@sok_dara')
            ->assertJsonPath('data.0.customer.telegram_user_id', 987654321)
            ->assertJsonPath('data.0.order.session.name', 'Morning Drinks')
            ->assertJsonPath('data.0.order.order_total', '14000.00')
            ->assertJsonPath('data.0.items.0.sugar_percent', 50)
            ->assertJsonPath('data.0.items.0.quantity', 2)
            ->assertJsonPath('data.0.items.0.item_subtotal', '10000.00')
            ->assertJsonPath('data.0.items.1.sugar_percent', null)
            ->assertJsonPath('data.0.items.1.item_subtotal', '4000.00');

        $this->getJson('/api/admin/reports/daily-orders?search=%40sok_dara', $headers)
            ->assertOk()
            ->assertJsonPath('total', 1);
        $this->getJson('/api/admin/reports/daily-orders?search=Sok%20Dara', $headers)
            ->assertOk()
            ->assertJsonPath('total', 1);
        $this->getJson('/api/admin/reports/daily-orders?status=cancelled', $headers)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.order.status', 'cancelled');

        $this->travelBack();
    }

    public function test_expired_session_summaries_group_sugar_and_are_sent_once(): void
    {
        config(['services.telegram.bot_token' => 'test-bot-token']);
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 501],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $group = TelegramGroup::create([
            'telegram_chat_id' => -100123456789,
            'title' => 'Cafe Group',
            'group_name' => 'Cafe Group',
            'verify_token' => 'CAFE-EXPIRY-TOKEN',
            'is_verified' => true,
            'is_active' => true,
        ]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-EXPIRE-001',
            'title' => 'Expired Drinks',
            'status' => 'open',
            'started_at' => now()->subHour(),
            'expires_at' => now()->subSecond(),
            'created_by' => $admin->id,
        ]);
        $customers = User::factory()->count(3)->create();
        $orders = [
            Order::create([
                'order_number' => 'ORD-EXPIRE-001',
                'order_session_id' => $session->id,
                'user_id' => $customers[0]->id,
                'total_amount' => '5000.00',
                'status' => 'submitted',
                'submitted_at' => now()->subMinutes(30),
            ]),
            Order::create([
                'order_number' => 'ORD-EXPIRE-002',
                'order_session_id' => $session->id,
                'user_id' => $customers[1]->id,
                'total_amount' => '14000.00',
                'status' => 'submitted',
                'submitted_at' => now()->subMinutes(20),
            ]),
            Order::create([
                'order_number' => 'ORD-EXPIRE-CANCELLED',
                'order_session_id' => $session->id,
                'user_id' => $customers[2]->id,
                'total_amount' => '100000.00',
                'status' => 'cancelled',
                'submitted_at' => now()->subMinutes(10),
            ]),
        ];
        foreach ([
            [$orders[0], 'Ice Latte', '5000.00', 1, '5000.00', 50],
            [$orders[1], 'Ice Latte', '5000.00', 2, '10000.00', 50],
            [$orders[1], 'Americano', '4000.00', 1, '4000.00', 0],
            [$orders[2], 'Cancelled Drink', '100000.00', 1, '100000.00', 25],
        ] as [$order, $name, $price, $quantity, $subtotal, $sugar]) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_name' => $name,
                'unit_price' => $price,
                'quantity' => $quantity,
                'subtotal' => $subtotal,
                'sugar_percent' => $sugar,
            ]);
        }

        $noOrdersSession = OrderSession::create([
            'order_number' => 'SESSION-EXPIRE-002',
            'title' => 'Empty Session',
            'status' => 'open',
            'started_at' => now()->subHour(),
            'expires_at' => now()->subSecond(),
            'created_by' => $admin->id,
        ]);

        Artisan::call('order-sessions:expire');
        Artisan::call('order-sessions:expire');

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => $request['chat_id'] === $group->telegram_chat_id
            && str_contains($request['text'], 'Session: Expired Drinks')
            && str_contains($request['text'], 'Status: expired')
            && str_contains($request['text'], '- Ice Latte 50% x3 Unit price: 5000៛; Line total: 15000៛')
            && str_contains($request['text'], '- Americano 0% x1 Price: 4000៛')
            && str_contains($request['text'], '<b>Total Price: 19000៛</b>')
            && ! str_contains($request['text'], 'Cancelled Drink'));
        Http::assertSent(fn (HttpRequest $request) => str_contains($request['text'], 'Session: Empty Session')
            && str_contains($request['text'], 'No orders were placed in this session.'));
        $this->assertDatabaseHas('order_sessions', [
            'id' => $noOrdersSession->id,
            'status' => 'expired',
        ]);
        $this->assertSame(2, TelegramMessage::whereIn('order_session_id', [$session->id, $noOrdersSession->id])
            ->where('status', 'sent')
            ->count());
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
