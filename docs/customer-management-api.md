# Admin Customer Management API

These endpoints extend the existing Laravel API. They use the existing `users` rows as customer identities, because the Mini App already verifies Telegram `initData`, stores the Telegram numeric ID uniquely on `users`, and associates orders through `orders.user_id`. Order items retain their historical product name and price snapshots.

Every endpoint below requires a Sanctum bearer token for an active admin (`auth:sanctum` and `admin` middleware). Base path: `/api/admin`.

## Customer list

`GET /api/admin/customers`

Query parameters:

| Parameter | Meaning |
| --- | --- |
| `date_from`, `date_to` | Optional inclusive `YYYY-MM-DD` range, interpreted in `BUSINESS_TIMEZONE`. With neither provided, both default to today. Invalid dates and reversed ranges return `422`. A single provided boundary is allowed. |
| `search` | Search by Telegram ID, username, first name, last name, or display name. |
| `per_page` | Optional pagination size from 1 to 100 (default 15). |
| `include_inactive` | Optional boolean. Defaults to false; set true to include deactivated customers. |

Example: `GET /api/admin/customers?date_from=2026-10-01&date_to=2026-10-13&search=ahseven`

The paginated `data` entries include `telegram_user_id`, `telegram_username`, `first_name`, `last_name`, `display_name`, `is_active`, `order_count`, `latest_order_at`, and `total_spending`. `order_count` counts all order statuses in the selected period; `total_spending` sums submitted orders only and excludes cancelled orders. The response includes effective date/timezone filters and a `totals_basis` description.

Example response:

```json
{
  "data": [{
    "id": 14,
    "telegram_user_id": 123456789,
    "telegram_username": "customer_name",
    "first_name": "Cafe",
    "last_name": "Customer",
    "display_name": "Cafe Customer",
    "is_active": true,
    "order_count": 2,
    "latest_order_at": "2026-10-13T12:15:00.000000Z",
    "total_spending": "18.50"
  }],
  "current_page": 1,
  "filters": {
    "date_from": "2026-10-13",
    "date_to": "2026-10-13",
    "timezone": "Asia/Bangkok"
  },
  "totals_basis": {
    "order_count": "All order statuses within the selected date range.",
    "total_spending": "Submitted orders within the selected date range; cancelled orders are excluded."
  }
}
```

## Customer order details

`GET /api/admin/customers/{customer}/orders`

Accepts the same date and pagination parameters plus `order_session_id` and `status` (`submitted` or `cancelled`). The response includes the verified Telegram customer identity and a paginated `orders` list. Each order includes order ID/number, session name/status, order time, order status/total, and actual `order_items` snapshots (`product_name`, `unit_price`, `quantity`, `subtotal`). It does not look up current product prices.

Example: `GET /api/admin/customers/14/orders?date_from=2026-10-01&date_to=2026-10-13&order_session_id=9&status=submitted`

Response shape:

```json
{
  "customer": {
    "id": 14,
    "telegram_user_id": 123456789,
    "telegram_username": "customer_name",
    "first_name": "Cafe",
    "last_name": "Customer",
    "display_name": "Cafe Customer",
    "is_active": true
  },
  "orders": {
    "data": [{
      "id": 92,
      "order_number": "ORD-20261013-12345",
      "order_session_id": 9,
      "session_name": "Lunch",
      "session_status": "closed",
      "ordered_at": "2026-10-13T12:15:00.000000Z",
      "total_amount": "8.50",
      "status": "submitted",
      "items": [{
        "id": 114,
        "product_id": 7,
        "product_name": "Latte",
        "unit_price": "4.25",
        "quantity": 2,
        "subtotal": "8.50"
      }]
    }],
    "current_page": 1,
    "total": 1
  },
  "filters": {
    "date_from": "2026-10-01",
    "date_to": "2026-10-13",
    "timezone": "Asia/Bangkok",
    "order_session_id": 9,
    "status": "submitted"
  }
}
```

## Send a private Telegram message

`POST /api/admin/customers/{customer}/messages`

Request:

```json
{
  "message": "Hello, your order is ready. Please collect it."
}
```

`message` is required and limited to Telegram's 4096-character message size. A successful response has `delivered: true` and a message log with `status: "sent"`. Failed sends are recorded with `status: "failed"` and are never returned as delivered. Telegram rate limits return `429` and include `Retry-After` when Telegram supplies a retry delay.

Successful response:

```json
{
  "message": "The private Telegram message was delivered.",
  "delivered": true,
  "data": {
    "id": 8,
    "customer_id": 14,
    "admin_id": 1,
    "message": "Hello, your order is ready. Please collect it.",
    "status": "sent",
    "telegram_message_id": 246,
    "error": null,
    "created_at": "2026-10-13T12:15:00.000000Z",
    "sent_at": "2026-10-13T12:15:01.000000Z"
  }
}
```

Bots cannot start private conversations with arbitrary users. If Telegram reports that the private chat is unavailable, the failure response explains that the customer must open [@ahseven_bot](https://t.me/ahseven_bot) privately and press **Start** (and unblock the bot if necessary), then the admin can retry. The bot token remains server-side.

## Deactivate a customer

`DELETE /api/admin/customers/{customer}`

This sets the existing `users.is_active` flag to `false`; it does not delete the user, orders, or order items. Deactivated customers disappear from the default customer list and Mini App authentication rejects their disabled account. Their order reports remain available by customer ID, and admins can include them in list results with `include_inactive=true`.

Example response:

```json
{
  "message": "The customer was removed from the active customer list. Order history was preserved.",
  "data": {
    "id": 14,
    "telegram_user_id": 123456789,
    "is_active": false,
    "order_history_preserved": true
  }
}
```

## Super-admin payment QR

`POST /api/admin/users/{user}/payment-qr` uploads or replaces the public payment QR image for an admin account. It requires an active admin Sanctum token and the signed-in account must have `role: "superadmin"`. The target must be an admin account (`is_admin: true`). Send multipart form data with an `image` field; accepted formats are JPEG, PNG, and WebP, up to 5 MB.

The existing `GET /api/admin/users` response includes each account's `payment_qr_image_url` (null until configured). A successful upload returns:

```json
{
  "message": "Payment QR image updated successfully.",
  "data": {
    "user_id": 3,
    "payment_qr_image_url": "https://example.com/storage/payment-qr-codes/example.png"
  }
}
```

When a customer submits an order through `POST /api/mini-app/orders`, the response includes the QR URL belonging to the admin account that created the order session. `GET /api/mini-app/my-order` also returns `payment_qr_image_url` at the response root so the Mini App can restore the QR after a refresh:

```json
{
  "data": {
    "id": 92,
    "total_amount": "8.50"
  },
  "payment_qr_image_url": "https://example.com/storage/payment-qr-codes/example.png"
}
```

The separate [ordercafe-front](https://github.com/Chin-xiao/ordercafe-front) repository renders this image after checkout and on order refresh. Its **Later paid** action closes the Telegram Mini App; **Done paid** displays a thank-you message and then closes it. If the URL is null, no QR has been configured for that session's account. **Done paid** is a customer-side acknowledgement only; this API does not independently verify or record payment.

## Mini App identity and existing APIs

Order submission remains `POST /api/mini-app/orders`. The existing `telegram.mini.app` middleware verifies Telegram's signed `initData` from `X-Telegram-Init-Data` (or the existing `init_data` request input), upserts the user by unique Telegram ID, and uses that server-verified user for `orders.user_id`. Never use a frontend-supplied Telegram ID/name as customer identity.

Existing order, session, product, and reporting routes are unchanged. The React Admin UI is not included in this Laravel repository; it can consume these endpoints to add customer search/date controls, order details, a message composer, and a confirmation dialog before deactivation.

## Render deployment

1. Deploy the code and run the new migration with the existing release/deploy migration process (`php artisan migrate --force`) against the configured PostgreSQL database.
2. Keep the existing `TELEGRAM_BOT_TOKEN` Render environment variable set for `@ahseven_bot`; no new secret or frontend token is required.
3. Ensure `BUSINESS_TIMEZONE` is set to the business's timezone for date filtering (for example `Asia/Bangkok`).
4. Ensure the configured public filesystem disk serves uploaded QR images and uses persistent storage in production.
5. Deploy the Admin UI and Telegram Mini App separately; pass Telegram Web App `initData` unchanged to the existing Mini App API header.
