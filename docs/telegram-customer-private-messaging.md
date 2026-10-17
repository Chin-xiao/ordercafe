# Telegram customer identity and private messaging

## Ordering and identity

Customers can continue to order through the existing Telegram group `Order Now` button and Mini App launch parameter. Ordering requires a valid Telegram Web App `initData`; the backend derives the numeric Telegram user ID and profile from the signed payload, not from client-submitted identity fields.

The existing `users` customer is found by its unique `telegram_id`. Available non-empty username/first/last-name fields update that row without clearing known values when Telegram omits profile fields. `telegram_last_seen_at` is refreshed at most once every five minutes per verified Mini App request to avoid a write on every request. Opening the Mini App or placing an order does **not** set private chat activation or a private chat ID.

## Optional private activation

The customer can optionally open the existing bot in a private conversation using:

```text
https://t.me/ahseven_bot?start=order_notifications
```

Only an incoming private-chat Telegram `/start` update (including `/start order_notifications`) associates its actual `chat.id` with the existing customer identified by the verified webhook `from.id`. The customer is marked activated only after that update. A `/start` delivered from a group is ignored for private activation.

The public webhook route remains `POST /api/telegram/webhook`, but now requires Telegram's `X-Telegram-Bot-Api-Secret-Token` header. Set a long random `TELEGRAM_WEBHOOK_SECRET` in Render and configure Telegram's webhook with the same secret token. Do not send this secret to the React app or log it. Example Bot API configuration (run securely; substitute values from your secret manager, never commit them):

```text
setWebhook(url=<existing HTTPS webhook URL>, secret_token=<TELEGRAM_WEBHOOK_SECRET>)
```

The webhook update ID is stored uniquely. Duplicate updates return HTTP 200 without recreating customers or sending the welcome twice. Failed updates are retryable; a processing claim older than two minutes can also be recovered after a worker crash.

## Admin private message endpoint

The existing protected endpoint remains:

```http
POST /api/admin/customers/{customer}/messages
Authorization: Bearer <admin Sanctum token>
Content-Type: application/json

{"message":"Your drink is ready."}
```

Before private activation it returns `409 Conflict` and does not call Telegram or create a delivery record:

```json
{
  "message": "The customer must open @ahseven_bot in a private chat and press Start before private messages can be delivered.",
  "delivered": false,
  "error_code": "PRIVATE_CHAT_NOT_STARTED"
}
```

After activation, successful sends return `delivered: true` only when Telegram accepts the send. The backend uses the stored private chat ID, never a group chat ID and never assumes the numeric user ID by itself grants permission. Failure responses include structured codes, including `BOT_BLOCKED_BY_CUSTOMER`, `PRIVATE_CHAT_UNAVAILABLE`, `TELEGRAM_FORBIDDEN`, `TELEGRAM_RATE_LIMITED`, and `TELEGRAM_NETWORK_ERROR`. A Telegram `403`/blocked response does not erase the recorded activation: the customer must unblock the bot before another attempt can succeed.

The Admin customer response includes `private_chat_activated`, `private_chat_activated_at`, and `telegram_last_seen_at`. The private chat ID itself is not returned.

## Migration and Render deployment

1. Deploy the application and run `php artisan migrate --force` against the existing PostgreSQL database. The migration adds nullable customer activation/last-seen fields and a unique Telegram update-ID ledger; it does not modify or delete existing customer/order rows.
2. Add `TELEGRAM_WEBHOOK_SECRET` in Render as a high-entropy value of 1–256 characters using Telegram's allowed `[A-Za-z0-9_-]` characters.
3. Configure Telegram's existing webhook URL with that same `secret_token`. Verify a real Telegram update reaches the deployed route with the header; requests without it receive `401`, and a missing server configuration receives `503`.
4. Keep `TELEGRAM_BOT_TOKEN`, `TELEGRAM_MINI_APP_URL`, and existing Mini App launch configuration unchanged.
5. Deploy the Admin frontend separately if it should display activation status. Existing order routes and group ordering do not require customers to activate private messaging.
