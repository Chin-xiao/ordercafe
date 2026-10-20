# Drink sugar options and order reports

This extends the existing Mini App order submission, order-item snapshots, Telegram group delivery ledger, and Sanctum-protected Admin API. All amounts and item names are read from the stored order records; a client-submitted price is ignored.

## Drink sugar options

`GET /api/mini-app/products` and `GET /api/mini-app/categories` include the available options alongside their existing `data` property:

```json
{
  "data": [],
  "sugar_percent_options": [0, 25, 50, 75, 100]
}
```

Submit the selected value on each `items[]` entry. The field is optional for compatibility with older clients, and omitted historical values remain `null`.

```json
{
  "order_session_id": 12,
  "items": [
    { "product_id": 7, "quantity": 1, "sugar_percent": 50 },
    { "product_id": 8, "quantity": 1, "sugar_percent": 0 }
  ]
}
```

The existing Mini App authentication header is still required. The server validates sugar against `config/orders.php`, looks up each available product, and stores the product's current database price in the order-item snapshot. Same-product lines may use different sugar percentages. The response contains `sugar_percent` on each returned item; items created before this migration return `null`.

## Daily detailed Admin report

`GET /api/admin/reports/daily-orders` requires the existing active-admin Sanctum bearer token.

| Query parameter | Meaning |
| --- | --- |
| `date_from`, `date_to` | Inclusive `YYYY-MM-DD` boundaries in `BUSINESS_TIMEZONE`. If neither is given, both default to today. Either boundary may be supplied alone. |
| `order_session_id` | Optional existing session filter. |
| `status` | Optional `submitted` or `cancelled`; without it, only submitted orders are reported. |
| `search` | Search Telegram numeric ID, username, first/last name, or display name. |
| `per_page` | Page size from 1 to 100; default 15. Pagination is over orders, not joined item rows. |

Example:

```http
GET /api/admin/reports/daily-orders?date_from=2026-10-16&date_to=2026-10-16&search=987654321&per_page=15
Authorization: Bearer <admin-token>
```

Response data keeps customer identity, order/session details, order total, and item snapshots at separate levels. Duplicate lines for the same drink, sugar, and unit-price snapshot are combined within each order; the order total is not repeated once for every item:

```json
{
  "data": [{
    "customer": {
      "id": 23,
      "display_name": "Sok Dara",
      "telegram_username": "@sok_dara",
      "telegram_user_id": 987654321
    },
    "order": {
      "id": 91,
      "order_number": "ORD-20261016-12345",
      "status": "submitted",
      "order_time": "2026-10-16T08:30:00+07:00",
      "session": { "id": 12, "name": "Morning Drinks", "status": "closed" },
      "order_total": "9000.00"
    },
    "items": [
      {
        "product_id": 7,
        "drink_name": "Ice Latte",
        "sugar_percent": 50,
        "quantity": 1,
        "unit_price": "5000.00",
        "item_subtotal": "5000.00"
      },
      {
        "product_id": 8,
        "drink_name": "Americano",
        "sugar_percent": 0,
        "quantity": 1,
        "unit_price": "4000.00",
        "item_subtotal": "4000.00"
      }
    ]
  }],
  "current_page": 1,
  "per_page": 15,
  "total": 1,
  "filters": {
    "date_from": "2026-10-16",
    "date_to": "2026-10-16",
    "order_session_id": null,
    "status": null,
    "search": "987654321",
    "timezone": "Asia/Bangkok"
  }
}
```

## Telegram session announcements and summaries

Starting a session uses the existing verified Telegram groups, bot token, and `TELEGRAM_MINI_APP_URL`. The configured Telegram deep link receives `startapp=order_<session-id>` and the inline button is labeled `🛒 Order Now`. A delivery record with a unique idempotency key prevents concurrent start retries from posting duplicate announcements. Failed announcements remain visible to the Admin API and can be retried through the existing announcement retry route.

Manual close and scheduled expiration produce an HTML-formatted summary containing the session name/status, submitted orders only, and a grand total from stored order totals. Lines are grouped by drink, sugar percentage, and unit-price snapshot. Repeated products with different sugar remain separate. Quantities above one show both unit price and line total. Empty sessions receive an explicit no-orders message. Long summaries are sent in Telegram-safe chunks.

The existing `order-sessions:expire` command now sends the summary when it atomically changes an open session to expired. Ensure the Render scheduler invokes `php artisan schedule:run` every minute (or keep the existing scheduler worker running); no additional queue or bot is introduced.

Admins can send a plain-text message to the newest active, verified Telegram group from an order-session section:

```http
POST /api/admin/order-sessions/{orderSession}/messages
Authorization: ****** Sanctum token>
Content-Type: application/json

{"message":"The cafe will close early today."}
```

The endpoint is restricted to authenticated admins, accepts non-empty messages up to Telegram's 4096-character limit, and records the delivery in `telegram_messages`. A successful response returns `delivered: true`; missing Telegram configuration or a Telegram delivery failure returns a non-success response and does not report the message as sent.

## Render/PostgreSQL deployment

1. Deploy the code and run `php artisan migrate --force` against the existing Render PostgreSQL database. The migration adds a nullable sugar snapshot and a nullable unique Telegram delivery key; existing orders retain `NULL` sugar.
2. Keep the already-configured `TELEGRAM_BOT_TOKEN` and `TELEGRAM_MINI_APP_URL` Render environment variables unchanged. The app validates the configured Mini App link against the existing bot; do not replace it with a guessed URL.
3. Set `BUSINESS_TIMEZONE` to the business timezone so report dates use local midnight boundaries.
4. Confirm the Render scheduler runs each minute so automatic expiration and its summary are processed.
5. Deploy the Admin UI/Mini App separately. The Mini App can read the sugar options from the existing product endpoints and call the detailed report with the existing admin bearer token.
