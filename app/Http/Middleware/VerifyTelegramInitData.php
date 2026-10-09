<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramInitData
{
    public function handle(Request $request, Closure $next): Response
    {
        $initData = $request->header('X-Telegram-Init-Data') ?: $request->input('init_data');

        if (! is_string($initData) || $initData === '') {
            return response()->json([
                'message' => 'Unauthorized: Missing Telegram initialization data.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $botToken = config('services.telegram.bot_token');

        if (! $botToken) {
            return response()->json([
                'message' => 'Server configuration error: Telegram bot token not set.',
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $validatedData = $this->validateAndParseInitData($initData, $botToken);

        if (! $validatedData || ! isset($validatedData['user']['id'])) {
            return response()->json([
                'message' => 'Unauthorized: Invalid Telegram signature or expired data.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $userData = $validatedData['user'];
        $request->attributes->set('telegram_web_app_start_param', $validatedData['start_param']);

        $user = User::firstOrCreate(
            ['telegram_id' => $userData['id']],
            [
                'name' => $userData['first_name'] ?? $userData['username'] ?? 'Telegram user',
                'email' => 'telegram'.$userData['id'].'@users.invalid',
                'password' => Hash::make(Str::random(64)),
                'username' => $userData['username'] ?? null,
                'first_name' => $userData['first_name'] ?? null,
                'last_name' => $userData['last_name'] ?? null,
                'role' => 'user',
                'is_active' => true,
            ]
        );

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Forbidden: Your account has been disabled.',
            ], Response::HTTP_FORBIDDEN);
        }

        $profile = [
            'name' => trim(implode(' ', array_filter([
                $userData['first_name'] ?? null,
                $userData['last_name'] ?? null,
            ]))) ?: ($userData['username'] ?? $user->name),
            'username' => $userData['username'] ?? null,
            'first_name' => $userData['first_name'] ?? null,
            'last_name' => $userData['last_name'] ?? null,
        ];
        $user->fill($profile)->save();

        $request->setUserResolver(fn () => $user);

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

        if (! isset($params['hash'], $params['auth_date'], $params['user'])
            || ! is_string($params['hash'])
            || ! preg_match('/^[a-f0-9]{64}$/i', $params['hash'])
            || ! ctype_digit((string) $params['auth_date'])) {
            return null;
        }

        $receivedHash = $params['hash'];
        unset($params['hash']);

        ksort($params);

        $dataCheckString = collect($params)
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode("\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($calculatedHash, $receivedHash)) {
            return null;
        }

        $authDate = (int) $params['auth_date'];
        if ($authDate > time() + 30 || time() - $authDate > 86400) {
            return null;
        }

        $userJson = json_decode($params['user'], true);

        if (! is_array($userJson)
            || ! isset($userJson['id'])
            || ! ctype_digit((string) $userJson['id'])) {
            return null;
        }

        return [
            'user' => $userJson,
            'start_param' => $params['start_param'] ?? null,
        ];
    }
}
