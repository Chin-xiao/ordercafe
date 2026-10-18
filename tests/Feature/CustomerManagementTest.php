<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderSession;
use App\Models\Product;
use App\Models\TelegramCustomerMessage;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
            ->assertJsonPath('data.0.private_chat_activated', false)
            ->assertJsonPath('data.0.private_chat_activation_url', 'https://t.me/ahseven_bot?start=order_notifications')
            ->assertJsonPath('data.0.order_count', 3)
            ->assertJsonPath('data.0.total_spending', '10.00')
            ->assertJsonPath('filters.timezone', 'Asia/Bangkok')
            ->assertJsonPath('totals_basis.total_spending', 'Submitted orders within the selected date range; cancelled orders are excluded.');

    }

    public function test_customer_admin_routes_require_an_authenticated_admin(): void
    {
        $this->getJson('/api/admin/customers')->assertUnauthorized();
        $customer = $this->customer(812345677);
        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Unauthorized send attempt.',
        ], ['Idempotency-Key' => 'unauthorized-message'])->assertUnauthorized();
        $nonAdmin = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->getJson('/api/admin/customers', $this->headers($nonAdmin))->assertForbidden();
        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Unauthorized send attempt.',
        ], $this->headers($nonAdmin) + ['Idempotency-Key' => 'non-admin-message'])->assertForbidden();
    }

    public function test_admin_can_list_verified_mini_app_customers_before_they_place_an_order(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->customer(812345676, [
            'username' => 'mini_app_only',
            'first_name' => 'Mini',
            'last_name' => 'App',
            'name' => 'Mini App',
        ]);
        $headers = $this->headers($admin);

        $this->getJson('/api/admin/customers', $headers)
            ->assertOk()
            ->assertJsonPath('total', 0);
        $this->getJson('/api/admin/customers?include_without_orders=1', $headers)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.telegram_user_id', 812345676)
            ->assertJsonPath('data.0.telegram_username', 'mini_app_only')
            ->assertJsonPath('data.0.display_name', 'Mini App')
            ->assertJsonPath('data.0.private_chat_activated', false)
            ->assertJsonPath('data.0.private_chat_activation_url', 'https://t.me/ahseven_bot?start=order_notifications')
            ->assertJsonPath('data.0.order_count', 0);
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
        $customer = $this->customer(856789123, [
            'telegram_private_chat_id' => 856789123,
            'telegram_private_chat_activated_at' => now(),
        ]);
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
            ], 403)
            ->push([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: chat not found',
            ], 400);
        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Your order is ready.',
        ], $headers)->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Your order is ready.',
        ], $headers + ['Idempotency-Key' => 'ready-order-001'])->assertOk()
            ->assertJsonPath('delivered', true)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.telegram_message_id', 246)
            ->assertJsonPath('data.admin_id', $admin->id)
            ->assertJsonPath('data.message', 'Your order is ready.');

        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => '   ',
        ], $headers + ['Idempotency-Key' => 'blank-message'])->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Your order is ready.',
        ], $headers + ['Idempotency-Key' => 'ready-order-001'])->assertOk()
            ->assertJsonPath('delivered', true)
            ->assertJsonPath('data.telegram_message_id', 246);
        Http::assertSentCount(1);

        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'A different message.',
        ], $headers + ['Idempotency-Key' => 'ready-order-001'])->assertStatus(409)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_KEY_REUSED');

        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Second attempt.',
        ], $headers + ['Idempotency-Key' => 'ready-order-002'])->assertStatus(403)
            ->assertJsonPath('delivered', false)
            ->assertJsonPath('error_code', 'BOT_BLOCKED_BY_CUSTOMER')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.telegram_message_id', null)
            ->assertJsonPath('error', 'The customer blocked @ahseven_bot. They must unblock the bot before Telegram can deliver private messages.');

        $this->assertNull($customer->fresh()->telegram_private_chat_id);
        $this->assertNull($customer->fresh()->telegram_private_chat_activated_at);
        $invalidChatCustomer = $this->customer(856789124, [
            'telegram_private_chat_id' => 856789124,
            'telegram_private_chat_activated_at' => now(),
        ]);
        $this->postJson("/api/admin/customers/{$invalidChatCustomer->id}/messages", [
            'message' => 'Please check your private chat.',
        ], $headers + ['Idempotency-Key' => 'invalid-private-chat'])->assertStatus(409)
            ->assertJsonPath('error_code', 'PRIVATE_CHAT_UNAVAILABLE')
            ->assertJsonPath('data.status', 'failed');
        $this->assertNull($invalidChatCustomer->fresh()->telegram_private_chat_id);
        $this->assertNull($invalidChatCustomer->fresh()->telegram_private_chat_activated_at);

        $this->assertDatabaseCount('telegram_customer_messages', 3);
        $this->assertSame(
            ['sent', 'failed', 'failed'],
            TelegramCustomerMessage::query()->orderBy('id')->pluck('status')->all()
        );
        Http::assertSent(fn ($request) => $request['chat_id'] === 856789123);
    }

    public function test_private_activation_is_optional_for_ordering_and_enables_admin_messages_after_webhook_start(): void
    {
        $botToken = 'test-private-start-bot-token';
        $webhookSecret = 'test-webhook-secret-value';
        config([
            'services.telegram.bot_token' => $botToken,
            'services.telegram.webhook_secret' => $webhookSecret,
        ]);
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 456],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $customer = $this->customer(889123456, [
            'username' => 'known_customer',
            'first_name' => 'Known',
            'last_name' => 'Customer',
        ]);
        $session = OrderSession::create([
            'order_number' => 'OPTIONAL-PRIVATE-SESSION',
            'title' => 'Open Ordering',
            'status' => 'open',
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'created_by' => $admin->id,
        ]);
        $product = Product::create([
            'name' => 'Latte',
            'price' => '5.00',
            'is_available' => true,
        ]);
        $initData = $this->signedInitData($botToken, [
            'auth_date' => (string) time(),
            'user' => json_encode([
                'id' => 889123456,
                'first_name' => 'Known',
                'last_name' => 'Customer',
                'username' => 'known_customer',
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->postJson('/api/mini-app/orders', [
            'order_session_id' => $session->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'sugar_percent' => 50]],
        ], ['X-Telegram-Init-Data' => $initData])
            ->assertCreated()
            ->assertJsonPath('data.items.0.sugar_percent', 50);
        $customer->refresh();
        $this->assertNotNull($customer->telegram_last_seen_at);
        $this->assertNull($customer->telegram_private_chat_id);
        $this->assertNull($customer->telegram_private_chat_activated_at);

        $adminHeaders = $this->headers($admin);
        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Your drink is ready.',
        ], $adminHeaders + ['Idempotency-Key' => 'drink-ready-before-start'])->assertStatus(409)
            ->assertJsonPath('delivered', false)
            ->assertJsonPath('error_code', 'PRIVATE_CHAT_NOT_STARTED')
            ->assertJsonPath('activation_url', 'https://t.me/ahseven_bot?start=order_notifications');
        Http::assertNothingSent();

        $webhookHeaders = ['X-Telegram-Bot-Api-Secret-Token' => $webhookSecret];
        $this->postJson('/api/telegram/webhook', [
            'update_id' => 91001,
            'message' => [
                'text' => '/start order_notifications',
                'chat' => ['id' => 889123456, 'type' => 'private'],
                'from' => ['id' => 889123456],
            ],
        ], $webhookHeaders)->assertOk()
            ->assertJsonPath('status', 'ok');

        $customer->refresh();
        $this->assertSame(889123456, $customer->telegram_private_chat_id);
        $this->assertNotNull($customer->telegram_private_chat_activated_at);
        $this->assertSame('known_customer', $customer->username);
        $this->assertSame('Known', $customer->first_name);
        $this->assertSame('Customer', $customer->last_name);
        $this->assertSame('Known Customer', $customer->name);
        $this->getJson('/api/admin/customers', $adminHeaders)->assertOk()
            ->assertJsonPath('data.0.private_chat_activated', true)
            ->assertJsonPath('data.0.private_chat_activation_url', null);
        $this->getJson('/api/mini-app/products', ['X-Telegram-Init-Data' => $initData])
            ->assertOk()
            ->assertJsonPath('private_messaging.activated', true)
            ->assertJsonPath('private_messaging.activation_url', null);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 91001,
            'message' => [
                'text' => '/start order_notifications',
                'chat' => ['id' => 889123456, 'type' => 'private'],
                'from' => ['id' => 889123456],
            ],
        ], $webhookHeaders)->assertOk()
            ->assertJsonPath('duplicate', true);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('telegram_webhook_updates', 1);
        Http::assertSentCount(1);

        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Your drink is ready.',
        ], $adminHeaders + ['Idempotency-Key' => 'drink-ready-after-start'])->assertOk()
            ->assertJsonPath('delivered', true)
            ->assertJsonPath('data.status', 'sent');
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['chat_id'] === 889123456
            && $request['text'] === 'Your drink is ready.');
    }

    public function test_webhook_requires_telegram_secret_and_group_start_does_not_activate_private_chat(): void
    {
        config([
            'services.telegram.bot_token' => 'test-private-start-bot-token',
            'services.telegram.webhook_secret' => 'configured-webhook-secret',
        ]);
        Http::fake();
        $customer = $this->customer(899123456);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 92001,
            'message' => [
                'text' => '/start',
                'chat' => ['id' => -100123456789, 'type' => 'supergroup'],
                'from' => ['id' => 899123456],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret'])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'WEBHOOK_UNAUTHORIZED');

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 92001,
            'message' => [
                'text' => '/start',
                'chat' => ['id' => -100123456789, 'type' => 'supergroup'],
                'from' => ['id' => 899123456],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'configured-webhook-secret'])
            ->assertOk();

        $this->assertNull($customer->fresh()->telegram_private_chat_id);
        $this->assertNull($customer->fresh()->telegram_private_chat_activated_at);
        $this->assertDatabaseCount('telegram_webhook_updates', 1);
        Http::assertNothingSent();
    }

    public function test_private_start_does_not_create_or_link_an_unverified_customer(): void
    {
        config([
            'services.telegram.bot_token' => 'test-private-start-bot-token',
            'services.telegram.webhook_secret' => 'configured-webhook-secret',
        ]);
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 778],
            ]),
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 92002,
            'message' => [
                'text' => '/start order_notifications',
                'chat' => ['id' => 899123457, 'type' => 'private'],
                'from' => ['id' => 899123457],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'configured-webhook-secret'])->assertOk();

        $this->assertSame(0, User::where('telegram_id', 899123457)->count());
        $this->assertDatabaseCount('telegram_webhook_updates', 1);
        Http::assertSent(fn ($request) => $request['chat_id'] === 899123457
            && str_contains($request['text'], 'open the Mini App'));
    }

    public function test_webhook_diagnostics_are_admin_only_sanitized_and_read_only(): void
    {
        $botToken = '12345678:ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghi';
        $webhookSecret = 'configured-webhook-secret';
        config([
            'services.telegram.bot_token' => $botToken,
            'services.telegram.webhook_secret' => $webhookSecret,
        ]);
        Http::fake([
            'api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://private.example/webhook/path',
                    'pending_update_count' => 7,
                    'last_error_date' => Carbon::parse('2026-10-17 10:00:00', 'UTC')->timestamp,
                    'last_error_message' => 'Failure at https://private.example/webhook '.$botToken.' '.$webhookSecret.' CAFE_ABCDEFGHIJKLMNOPQRSTUVWXYZ123456',
                ],
            ]),
        ]);

        $this->getJson('/api/admin/telegram-webhook-diagnostics')->assertUnauthorized();
        $nonAdmin = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $this->getJson('/api/admin/telegram-webhook-diagnostics', $this->headers($nonAdmin))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $inactiveAdmin = User::factory()->create(['is_admin' => true, 'is_active' => false]);
        $this->getJson('/api/admin/telegram-webhook-diagnostics', $this->headers($inactiveAdmin))->assertForbidden();
        $this->app['auth']->forgetGuards();
        Http::assertNothingSent();

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $headers = $this->headers($admin);
        $usersBefore = User::count();
        $tokensBefore = \Illuminate\Support\Facades\DB::table('personal_access_tokens')->count();
        $groupsBefore = TelegramGroup::count();
        $updatesBefore = \Illuminate\Support\Facades\DB::table('telegram_webhook_updates')->count();

        $response = $this->get('/api/admin/telegram-webhook-diagnostics', $headers)
            ->assertOk()
            ->assertExactJson([
                'webhook_url_configured' => true,
                'pending_update_count' => 7,
                'last_error_date' => '2026-10-17T10:00:00+00:00',
                'last_error_message' => 'Failure at [URL REDACTED] [REDACTED] [REDACTED] [REDACTED]',
            ]);

        $responseBody = $response->getContent();
        $this->assertStringContainsString(
            'no-store',
            strtolower((string) $response->headers->get('Cache-Control'))
        );
        $this->assertStringNotContainsString($botToken, $responseBody);
        $this->assertStringNotContainsString($webhookSecret, $responseBody);
        $this->assertStringNotContainsString('private.example', $responseBody);
        $this->assertSame($usersBefore, User::count());
        $this->assertSame($tokensBefore, \Illuminate\Support\Facades\DB::table('personal_access_tokens')->count());
        $this->assertSame($groupsBefore, TelegramGroup::count());
        $this->assertSame($updatesBefore, \Illuminate\Support\Facades\DB::table('telegram_webhook_updates')->count());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/getWebhookInfo'));
    }

    public function test_webhook_diagnostics_reject_input_and_hide_telegram_api_failures(): void
    {
        config(['services.telegram.bot_token' => 'test-diagnostics-bot-token']);
        Http::fake([
            'api.telegram.org/*/getWebhookInfo' => Http::response([
                'ok' => false,
                'description' => 'Sensitive upstream details',
            ], 500),
        ]);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $headers = $this->headers($admin);

        $this->get('/api/admin/telegram-webhook-diagnostics?url=https://example.com', $headers)
            ->assertBadRequest()
            ->assertContent('');
        Http::assertNothingSent();

        $this->get('/api/admin/telegram-webhook-diagnostics', $headers)
            ->assertStatus(502)
            ->assertContent('');
    }

    public function test_admin_can_create_multiple_group_tokens_without_unlinking_verified_groups(): void
    {
        config([
            'services.telegram.bot_token' => 'test-group-bot-token',
            'services.telegram.webhook_secret' => 'configured-webhook-secret',
        ]);
        Http::fake([
            'api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 779],
            ]),
        ]);

        $admin = User::factory()->create(['is_admin' => true]);
        $existing = TelegramGroup::create([
            'telegram_chat_id' => -100123456789,
            'title' => 'Existing Cafe Group',
            'group_name' => 'Existing Cafe Group',
            'verify_token' => 'EXISTING-GROUP-TOKEN',
            'is_verified' => true,
            'is_active' => false,
        ]);
        $headers = $this->headers($admin);
        $this->getJson('/api/admin/telegram-settings')->assertUnauthorized();
        $this->postJson('/api/admin/telegram-settings/regenerate')->assertUnauthorized();

        $initialToken = $this->getJson('/api/admin/telegram-settings', $headers)
            ->assertOk()
            ->assertJsonPath('data.chat_id', null)
            ->assertJsonPath('data.is_verified', false)
            ->json('data.verify_token');
        $this->assertNotSame('EXISTING-GROUP-TOKEN', $initialToken);
        $this->assertDatabaseHas('telegram_groups', [
            'id' => $existing->id,
            'telegram_chat_id' => -100123456789,
            'is_verified' => true,
            'is_active' => false,
        ]);

        $firstToken = $initialToken;
        $secondToken = $this->postJson('/api/admin/telegram-settings/regenerate', [], $headers)
            ->assertOk()
            ->assertJsonPath('data.chat_id', null)
            ->assertJsonPath('data.is_verified', false)
            ->json('data.verify_token');

        $webhookHeaders = ['X-Telegram-Bot-Api-Secret-Token' => 'configured-webhook-secret'];
        $this->postJson('/api/telegram/webhook', [
            'update_id' => 93001,
            'message' => [
                'text' => '/setup@ahseven_bot '.$firstToken,
                'chat' => ['id' => -100200000001, 'type' => 'supergroup', 'title' => 'New Group One'],
                'from' => ['id' => 123456789],
            ],
        ], $webhookHeaders)->assertOk();
        $this->postJson('/api/telegram/webhook', [
            'update_id' => 93002,
            'message' => [
                'text' => '/setup '.$secondToken,
                'chat' => ['id' => -100200000002, 'type' => 'group', 'title' => 'New Group Two'],
                'from' => ['id' => 123456789],
            ],
        ], $webhookHeaders)->assertOk()->assertJsonMissingPath('duplicate');
        $this->postJson('/api/telegram/webhook', [
            'update_id' => 93005,
            'message' => [
                'text' => '/setup@ahseven_bot '.$firstToken,
                'chat' => ['id' => -100200000001, 'type' => 'supergroup', 'title' => 'New Group One'],
                'from' => ['id' => 123456789],
            ],
        ], $webhookHeaders)->assertOk();

        $this->assertDatabaseCount('telegram_groups', 3);
        $this->assertDatabaseHas('telegram_groups', [
            'id' => $existing->id,
            'telegram_chat_id' => -100123456789,
            'group_name' => 'Existing Cafe Group',
            'is_verified' => true,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('telegram_groups', [
            'verify_token' => $firstToken,
            'telegram_chat_id' => -100200000001,
            'group_name' => 'New Group One',
            'is_verified' => true,
        ]);
        $this->assertDatabaseHas('telegram_groups', [
            'verify_token' => $secondToken,
            'telegram_chat_id' => -100200000002,
            'group_name' => 'New Group Two',
            'is_verified' => true,
        ]);

        $this->postJson('/api/telegram/webhook', [
            'update_id' => 93003,
            'message' => [
                'text' => '/setup INVALID_TOKEN',
                'chat' => ['id' => -100200000003, 'type' => 'supergroup', 'title' => 'Unauthorized Group'],
                'from' => ['id' => 123456789],
            ],
        ], $webhookHeaders)->assertOk();
        $this->postJson('/api/telegram/webhook', [
            'update_id' => 93004,
            'message' => [
                'text' => '/setup '.$firstToken,
                'chat' => ['id' => -100200000003, 'type' => 'supergroup', 'title' => 'Unauthorized Group'],
                'from' => ['id' => 123456789],
            ],
        ], $webhookHeaders)->assertOk();
        Http::assertSent(fn ($request) => $request['chat_id'] === -100200000002);
        $this->assertDatabaseCount('telegram_groups', 3);
        Http::assertSent(fn ($request) => $request['chat_id'] === -100200000003
            && str_contains($request['text'], 'Invalid or expired group verification token'));
        Http::assertSent(fn ($request) => $request['chat_id'] === -100200000003
            && str_contains($request['text'], 'already been used'));
    }

    public function test_telegram_rate_limits_are_reported_and_include_retry_after(): void
    {
        config(['services.telegram.bot_token' => 'test-message-bot-token']);
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $customer = $this->customer(867891234, [
            'telegram_private_chat_id' => 867891234,
            'telegram_private_chat_activated_at' => now(),
        ]);
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
        ], $this->headers($admin) + ['Idempotency-Key' => 'rate-limit-message'])->assertStatus(429)
            ->assertHeader('Retry-After', '12')
            ->assertJsonPath('delivered', false)
            ->assertJsonPath('error_code', 'TELEGRAM_RATE_LIMITED')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('error', 'Telegram is rate limiting messages. Please retry in 12 seconds.');
    }

    public function test_private_message_network_failures_return_structured_error(): void
    {
        config(['services.telegram.bot_token' => 'test-message-bot-token']);
        $admin = User::factory()->create(['is_admin' => true]);
        $customer = $this->customer(878123456, [
            'telegram_private_chat_id' => 878123456,
            'telegram_private_chat_activated_at' => now(),
        ]);
        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

        $this->postJson("/api/admin/customers/{$customer->id}/messages", [
            'message' => 'Please contact us.',
        ], $this->headers($admin) + ['Idempotency-Key' => 'network-error-message'])->assertStatus(502)
            ->assertJsonPath('delivered', false)
            ->assertJsonPath('error_code', 'TELEGRAM_NETWORK_ERROR')
            ->assertJsonPath('data.status', 'failed');
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
