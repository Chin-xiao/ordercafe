<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderSession;
use App\Models\Product;
use App\Models\TelegramGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
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
            'Authorization' => 'Bearer ' . $admin->createToken('test-admin')->plainTextToken,
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
            'X-Telegram-Init-Data' => 'auth_date=' . (time() - 90000) . '&user=%7B%22id%22%3A1%7D&hash=' . str_repeat('a', 64),
        ])->assertUnauthorized();
    }

    public function test_cors_allows_the_configured_render_frontend_origin(): void
    {
        $this->call('OPTIONS', '/api/admin/products', server: [
            'HTTP_ORIGIN' => 'https://ordercafe-front.onrender.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertNoContent()
            ->assertHeader('access-control-allow-origin', 'https://ordercafe-front.onrender.com');
    }

    public function test_starting_and_closing_a_session_notifies_verified_telegram_groups(): void
    {
        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.mini_app_url' => 'https://ordercafe-front.onrender.com/app',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $admin = User::factory()->create(['is_admin' => true]);
        $session = OrderSession::create([
            'order_number' => 'SESSION-TEST-002',
            'title' => 'Dinner',
            'status' => 'draft',
            'created_by' => $admin->id,
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
            'Authorization' => 'Bearer ' . $admin->createToken('test-admin')->plainTextToken,
        ];

        $this->postJson("/api/admin/order-sessions/{$session->id}/start", [], $headers)
            ->assertOk();
        $this->postJson("/api/admin/order-sessions/{$session->id}/close", [], $headers)
            ->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => $request['chat_id'] === -100123456789);
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
