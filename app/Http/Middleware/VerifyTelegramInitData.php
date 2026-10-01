<?php
namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramInitData
{
    public function handle(Request $request, Closure $next): Response
    {
        // Expecting initData string passed in the header (e.g., X-Telegram-Init-Data)
        $initData = $request->header('X-Telegram-Init-Data') ?: $request->input('init_data');

        if (!$initData) {
            return response()->json([
                'message' => 'Unauthorized: Missing Telegram initialization data.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $botToken = config('services.telegram.bot_token');

        if (!$botToken) {
            return response()->json([
                'message' => 'Server configuration error: Telegram bot token not set.'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        // Validate the initData signature and parse user payload
        $userData = $this->validateAndParseInitData($initData, $botToken);

        if (!$userData) {
            return response()->json([
                'message' => 'Unauthorized: Invalid Telegram signature or expired data.'
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Find or create the user in the database using telegram_id
        $user = User::firstOrCreate(
            ['telegram_id' => $userData['id']],
            [
                'username' => $userData['username'] ?? null,
                'first_name' => $userData['first_name'] ?? null,
                'last_name' => $userData['last_name'] ?? null,
                'role' => 'user',
                'is_active' => true,
            ]
        );

        // Check if user account has been deactivated
        if (!$user->is_active) {
            return response()->json([
                'message' => 'Forbidden: Your account has been disabled.'
            ], Response::HTTP_FORBIDDEN);
        }

        // Update user profile details if they changed on Telegram
        $user->update([
            'username' => $userData['username'] ?? $user->username,
            'first_name' => $userData['first_name'] ?? $user->first_name,
            'last_name' => $userData['last_name'] ?? $user->last_name,
        ]);

        // Automatically authenticate the user for this request lifecycle
        $request->setUserResolver(fn() => $user);

        return $next($request);
    }

    /**
     * Cryptographically validate Telegram initData query string.
     *
     * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-web-app
     */
    private function validateAndParseInitData(string $initData, string $botToken): ?array
    {
        parse_str($initData, $params);

        if (!isset($params['hash'])) {
            return null;
        }

        $receivedHash = $params['hash'];
        unset($params['hash']);

        // Sort keys alphabetically
        ksort($params);

        $dataCheckString = collect($params)
            ->map(fn($value, $key) => "{$key}={$value}")
            ->implode("\n");

        // Step 1: Generate secret key using HMAC-SHA256 with "WebAppData" as key and bot token as data
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);

        // Step 2: Calculate hash of the data-check-string using the secret key
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        // Step 3: Compare hashes safely against timing attacks
        if (!hash_equals($calculatedHash, $receivedHash)) {
            return null;
        }

        // Optional: Check data age to prevent replay attacks (e.g., must be within 24 hours)
        if (isset($params['auth_date'])) {
            $authDate = (int) $params['auth_date'];
            if (time() - $authDate > 86400) {
                return null; // Expired
            }
        }

        // Decode the user JSON payload returned by Telegram
        if (isset($params['user'])) {
            $userJson = json_decode($params['user'], true);
            return is_array($userJson) ? $userJson : null;
        }

        return null;
    }
}
