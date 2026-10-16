# Backend performance audit

## Findings and priority

1. **Repeated Mini App catalog reads.** Product/category endpoints eagerly load their relationships already (so no N+1 issue was found), but each request rebuilt the same catalog payload from PostgreSQL. They now use a 30-second per-instance file cache and product/category model events invalidate both payloads. This store avoids using Aiven for cache hits. Price and availability can be stale for at most the TTL on another Render instance; checkout still reads the current product record and verifies availability and price server-side. The full existing catalog fields and response envelope are retained; pagination was not introduced because it would change the Mini App response contract.
2. **Repeated Telegram customer profile writes.** Signed `initData` verification and unique `telegram_id` lookup remain unchanged. The middleware now saves profile data only when it differs, avoiding an update on repeat requests for an unchanged customer.
3. **Broad session-expiration update on reads.** `/mini-app/order-session/current` previously ran an update over every expired open session on every request. It now performs a read for the normal current-session path. A deep-linked expired session is presented as `expired` in that response without changing stored state; the existing scheduled expiration command remains responsible for persisting the transition and sending the closing summary. Checkout independently enforces the deadline.
4. **Per-item checkout writes.** The backend previously issued one insert per order item and then counted the items with another query. Product price lookup remains one bulk query, and order items are now inserted in one bulk statement inside the existing transaction. The duplicate-order rule and database uniqueness constraint remain intact; no new client idempotency contract was introduced.
5. **Order date/status access path.** Existing queries filter/order by `orders.status` and `orders.submitted_at`, but only a standalone status index existed. A migration replaces it with `(status, submitted_at DESC)`, which supports the common submitted-order date range and sort. PostgreSQL creates the replacement concurrently before dropping the old index; SQLite uses schema operations for tests.
6. **Telegram startup verification and external-call visibility.** Starting a session previously called `getMe` every time. Verified bot details are now cached for 10 minutes per bot-token fingerprint, and both `getMe` and `sendMessage` log elapsed time, status, and safe operation metadata. Telegram sending itself remains synchronous because current Admin responses report delivery outcome immediately; no production queue worker configuration was available to safely assume.

## What is measured

The API middleware emits one structured log entry per request. It records method/path (without query string), status, request duration, response bytes, SQL query count, total SQL duration, first query duration per connection, slow-query count, and up to five slow-query fingerprints. The first query duration includes lazy connection establishment when the connection is first opened in that request, but is not an isolated TLS/connection-handshake measurement. A fingerprint is a SHA-256 digest of the SQL template; SQL text, bindings, headers, Telegram `initData`, chat credentials, and bot tokens are not logged. Requests at or above `PERFORMANCE_SLOW_REQUEST_MS` (default 1000 ms) and queries at or above `PERFORMANCE_SLOW_QUERY_MS` (default 100 ms) are flagged. Client errors are logged without request bodies; server errors are logged without exception messages.

Telegram API calls have separate operation, HTTP status, and duration logs. Failed connections and Telegram error codes are logged without the request URL or token. The request timer starts at API middleware entry, so it does not include platform cold-start time before Laravel handles the request.

## Measurements available in this workspace

No live Render/Aiven connection or production telemetry was available to this audit. I did not make a production request or claim Render/Aiven latency values. The local feature tests use in-memory SQLite and therefore measure SQL counts, not network latency or PostgreSQL query plans:

- Repeated product fetch: the initial product response executes two catalog queries (product query plus eager-loaded category query); an unchanged cache hit executes zero catalog queries. An Admin product update invalidates the cache; the next Mini App response reloads the catalog and returns the new database price.
- Repeat request for an unchanged Telegram customer profile: zero `users` update statements.
- Four order-item snapshots in checkout: one `order_items` insert statement, with item rows still included in the order response.
- Request logging is asserted to contain request duration, response size, and query metrics without credential/body fields.

These are reproducible local query-count observations, not before/after wall-clock benchmarks. The code-level old query pattern required two catalog queries per request, one profile update per request, and one item insert plus one count query per item submission. Real latency deltas need Render logs and Aiven measurements after deployment.

## PostgreSQL/Render follow-up measurements

After deploy, compare request log distributions by `path` and `status` over a representative traffic window. Separate database total from request duration; inspect the slow-query fingerprints, then run `EXPLAIN (ANALYZE, BUFFERS)` with representative parameters directly on Aiven for the underlying query before adding further indexes. Check Aiven connection/query latency and connection limits, and correlate with Render instance restarts/cold starts and response duration. Do not enable SQL-binding/query-body logging to obtain these measurements.

The `submitted_at` composite index is created with PostgreSQL `CREATE INDEX CONCURRENTLY` to avoid blocking normal writes for the index build. The migration is intentionally non-transactional for PostgreSQL. Run it during deployment with the regular release migration command and check the migration result; if interrupted, inspect the index catalog before retrying.

## Render configuration and limitations

- No new environment variables are required. Optional log thresholds are `PERFORMANCE_SLOW_QUERY_MS`, `PERFORMANCE_SLOW_REQUEST_MS`, and `CATALOG_CACHE_SECONDS`; defaults are 100 ms, 1000 ms, and 30 seconds.
- Catalog and bot-profile caches use Render instance-local files. They require no Redis service and are bounded by TTL; different instances may serve old catalog display data until expiry. Model writes invalidate the current instance. Checkout always reloads trusted product price and availability from PostgreSQL.
- Keep the existing scheduler active each minute for session expiration. Telegram session-open and close messages still wait for Telegram's response; structured external-call timings will show whether that is material. Queueing those operations requires an explicitly configured Render worker and a durable queue backend, so this audit does not silently change their delivery timing or API response shape.
- A code change cannot remove Render free-instance cold starts or physical distance/network latency to Aiven. Upgrade/placement recommendations should follow observed Render duration and Aiven query/connection measurements.

## Deployment and verification

1. Deploy the code and run `php artisan migrate --force` against Aiven. The migration builds the status/submission-time index concurrently on PostgreSQL and replaces the prior status-only index.
2. Keep `TELEGRAM_BOT_TOKEN`, `TELEGRAM_MINI_APP_URL`, and the existing business-timezone configuration unchanged.
3. Keep the existing Render scheduler running `php artisan schedule:run` every minute.
4. Review structured API and Telegram duration logs after deployment; compare by route and inspect slow PostgreSQL query plans before choosing more infrastructure or index changes.
5. Run `php artisan test` and `vendor/bin/pint --test` for the changed/new files before release.
