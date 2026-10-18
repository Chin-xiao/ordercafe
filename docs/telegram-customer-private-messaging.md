# Telegram customer identity and private messaging

## Ordering and identity

Customers can continue to order through the existing Telegram group `Order Now` button and Mini App launch parameter. Ordering requires a valid Telegram Web App `initData`; the backend derives the numeric Telegram user ID and profile from the signed payload, not from client-submitted identity fields.

The existing `users` customer is found by its unique `telegram_id`. Available non-empty username/first/last-name fields update that row without clearing known values when Telegram omits profile fields. `telegram_last_seen_at` is refreshed at most once every five minutes per verified Mini App request to avoid a write on every request. Opening the Mini App or placing an order does **not** set private chat activation or a private chat ID.

## Optional private activation

The customer can optionally open the existing bot in a private conversation using:

```text
https://t.me/ahseven_bot?start=order_notifications
```

The authenticated Mini App products and categories responses include `private_messaging.activated` and `private_messaging.activation_url`. The React Mini App can use this URL to offer an optional “Enable private notifications” action; the URL itself does not activate messaging.

Only an incoming private-chat Telegram `/start` update (including `/start order_notifications`) associates its actual `chat.id` with the existing customer identified by the verified webhook `from.id`. The customer is marked activated only after that update. A `/start` delivered from a group is ignored for private activation.

The public webhook route remains `POST /api/telegram/webhook`, but now requires Telegram's `X-Telegram-Bot-Api-Secret-Token` header. Set a long random `TELEGRAM_WEBHOOK_SECRET` in Render and configure Telegram's webhook with the same secret token. Do not send this secret to the React app or log it. Example Bot API configuration (run securely; substitute values from your secret manager, never commit them):

```text
setWebhook(url=<existing HTTPS webhook URL>, secret_token=<TELEGRAM_WEBHOOK_SECRET>)
```

The webhook update ID is stored uniquely. Duplicate updates return HTTP 200 without recreating customers or sending the welcome twice. Failed updates are retryable; a processing claim older than two minutes can also be recovered after a worker crash.

## Group verification and webhook diagnostics

`telegram_groups` already permits multiple group rows. Admin `POST /api/admin/telegram-settings/regenerate` now creates a new pending token instead of clearing the currently verified group. Each token can verify one group; repeating the command in that same group is safe, while reusing it in another group is rejected. Paste the admin-provided `/setup@ahseven_bot TOKEN` command in the group or supergroup; the bot-addressed form allows Telegram to deliver it when group privacy mode is enabled.

The webhook secret check was added to the latest backend changes. If Telegram's existing webhook was not configured with the matching `secret_token`, Telegram requests receive `401` and group commands are never processed. A missing `TELEGRAM_WEBHOOK_SECRET` on the server returns `503`. Keep this check enabled and update the existing webhook's secret configuration; do not delete pending updates or reset the webhook.

Use the read-only Bot API `getWebhookInfo` to check the configured URL, pending update count, and recent delivery error. Do not call `getUpdates` while a webhook is configured; this application uses webhook delivery only. In a new group, add the bot with permission to send messages, generate a fresh token in Admin, then send `/setup@ahseven_bot TOKEN`. Telegram must deliver the command to the bot, and the bot must be permitted to send the confirmation. Invalid or already-used tokens receive an explanatory group reply and do not change group records.

## Admin private message endpoint

The existing protected endpoint remains:

```http
POST /api/admin/customers/{customer}/messages
Authorization: Bearer <admin Sanctum token>
Content-Type: application/json
Idempotency-Key: unique-client-generated-key

{"message":"Your drink is ready."}
```

The `Idempotency-Key` is required, scoped to the authenticated admin, and must be reused unchanged for retries of the same request. A repeated successful request returns the original delivery result without sending again. Reusing the key with different content or recipient returns `409 IDEMPOTENCY_KEY_REUSED`. Generate a new key for each intentional new message.

Before private activation it returns `409 Conflict` and does not call Telegram or create a delivery record:

```json
{
  "message": "The customer must open @ahseven_bot in a private chat and press Start before private messages can be delivered.",
  "delivered": false,
  "error_code": "PRIVATE_CHAT_NOT_STARTED"
}
```

After activation, successful sends return `delivered: true` only when Telegram accepts the send. The backend uses the stored private chat ID, never a group chat ID and never assumes the numeric user ID by itself grants permission. Failure responses include structured codes, including `BOT_BLOCKED_BY_CUSTOMER`, `PRIVATE_CHAT_UNAVAILABLE`, `TELEGRAM_FORBIDDEN`, `TELEGRAM_RATE_LIMITED`, and `TELEGRAM_NETWORK_ERROR`. A blocked bot or unavailable chat clears the stored activation; the customer must unblock the bot and press Start again before another attempt can succeed.

The Admin customer response includes `private_chat_activated`, `private_chat_activated_at`, `private_chat_activation_url` (null after activation), and `telegram_last_seen_at`. The private chat ID itself is not returned. The existing customer list remains order-filtered by default; use `GET /api/admin/customers?include_without_orders=1` to include verified Mini App customers who have not ordered yet. Configure the non-secret `TELEGRAM_BOT_USERNAME` value (defaults to `ahseven_bot`) if the deployed bot username changes.

## Migration and Render deployment

1. Deploy the application and run `php artisan migrate --force` against the existing PostgreSQL database. The new migration adds a nullable idempotency key to customer message records; it does not modify or delete existing customer, group, or order records. Earlier identity/activation migrations must also be applied if they are not already present.
2. Keep `TELEGRAM_BOT_TOKEN` and `TELEGRAM_MINI_APP_URL` configured as before. Set `TELEGRAM_BOT_USERNAME=ahseven_bot` and `TELEGRAM_WEBHOOK_SECRET` in Render. The webhook secret must be a high-entropy value of 1–256 characters using Telegram's allowed `[A-Za-z0-9_-]` characters.
3. Update Telegram's existing webhook URL with that same `secret_token`; do not switch to polling. Confirm delivery with `getWebhookInfo` and a fresh test update, without deleting pending updates.
4. Keep the existing group Order Now button and Mini App launch settings unchanged. Admin clients must send an `Idempotency-Key` with each new message request and reuse it for retries.
5. Test group setup in a new group and private messaging with a customer who first used the Mini App, then privately opened the bot and pressed Start. Live Telegram permissions, webhook status, and delivery still require manual verification against the deployed bot.
