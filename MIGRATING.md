# Upgrading Better Route

The Composer package version and your REST namespace version are independent.
Updating the library does not rename your `myapp/v1` routes. Treat changes to your
own response schemas and permissions as changes to your application's API contract.

## 1.1.0 to 1.1.1

This is a bug-fix release for existing APIs. It adds no Store API, cart, checkout,
or refund endpoints. Review the operational changes below before deploying.

### Coordinate idempotent writers before updating

Default response-cache and idempotency keys now include the router namespace,
concrete request route, and separately captured URL parameters. Previously a query
parameter could hide a URL ID, and two namespaces could share a replay record.
`RequestContext::routePath` remains the original route template.

Existing default cache entries become cold and expire normally. Existing
idempotency entries cannot be safely translated: they do not contain enough
information to identify the original namespace and target. There is deliberately
no fallback replay from ambiguous legacy records. No table-schema change is needed
when upgrading from 1.1.0.

For deployments using either idempotency middleware:

1. Pause all affected writers, retry queues, webhooks and client retries. Let
   already-running requests finish.
2. Reconcile unresolved operations against business records. Complete or retire
   retries under the old deployment before changing keys. Waiting for the configured
   record TTL alone does not prove that a client will never retry an old operation;
   account for the application's entire retry horizon.
3. Deploy every web/worker process together and restart long-running workers. Do
   not run old and new default key schemes concurrently.
4. Verify an identical retry replays and a changed payload conflicts, then resume
   new operations. Keep business-level deduplication for payments and other
   irreversible effects.

Do not clear old records as a substitute for reconciliation. A rollback also
crosses key schemes and needs the same pause/reconciliation procedure. Custom key
or fingerprint resolvers remain your responsibility; scope them to the actual
namespace, URL parameters, identity and any relevant tenant. If only a custom key
resolver is supplied, the corrected default fingerprint still changes.

Authentication must remain before caching and idempotency in the middleware
pipeline. Rate-limit buckets and single-use token semantics are unchanged.

### Authentication is scoped to the downstream pipeline

JWT, Bearer and Application Password middleware now restore the caller's native
WordPress user in `finally`, including when a downstream handler throws. Nested
middleware calls unwind their identities in reverse order. A verified JWT/Bearer
identity without a positive WP mapping runs downstream as native user `0`, rather
than inheriting an unrelated logged-in user.

`AuthContext::withIdentity()` replaces all derived fields (`userId`, `user`,
`claims`, `scopes`), including null/empty values. Do not use `array_key_exists()`
alone as proof that a request has a mapped user.

If you inject a custom `setCurrentUser` callback, also provide the appended optional
`getCurrentUser` callback so the middleware can restore that same identity store.
The default getter uses `get_current_user_id()` when WordPress is loaded, otherwise
it returns `0`. Existing constructor argument positions are unchanged.

The binding ends when the downstream pipeline returns. WordPress
`rest_request_after_callbacks`, `rest_post_dispatch` and later `_embed` processing
see the restored caller, not a lingering middleware identity. Perform protected
work inside the authenticated pipeline. Integrations that need authenticated
WordPress response filters/embedding should authenticate at WordPress's request
authentication boundary (for example native Application Password authentication).
Do not preserve access by disabling permission checks or leaving a global user set.

### WooCommerce order writes

- Address-only billing/shipping updates now recalculate taxes and totals, matching
  Woo's REST behavior. This can change an existing order's total, including a paid
  order. Review integrations that treated address changes as financially neutral.
- Requested status transitions are staged after item/tax calculations; status
  hooks now see final totals. Payment gateways are initialized before writes so
  their lifecycle hooks are available.
- Orders are saved before payment completion. On updates, `set_paid: true` calls
  `payment_complete()` only when the order still needs payment. Repeating it on a
  paid order does not repeat that event. Sending an already-paid status together
  with `set_paid` is not a guarantee of a payment-complete event; do not use this
  administrative flag as proof that an external gateway captured money.
- Order transactions cover database work. Emails, webhooks and external effects
  triggered by Woo hooks are not undone by a database rollback.

### Fractional quantities

Order quantities and product stock values no longer undergo Better Route integer
truncation. OpenAPI describes quantities as numbers; order input must be positive,
while product inventory still accepts negative values and `null`.

Writes follow the store's `wc_stock_amount()` configuration. A quantity that Woo
would change (for example `0.5` becoming `0`) is rejected with
`400 validation_failed` before persistence. Fractional stores must retain their
fractional stock configuration for subsequent reads and writes: Woo itself applies
that normalizer when loading order items. Better Route does not bypass Woo's data
store to recover values after the store configuration changes.

## 1.0.x to 1.1.x: consumer-visible security changes

Although 1.1.0 was published as a minor version, several security corrections
change existing behavior. Review these explicitly rather than assuming an
unattended `^1.0` update is behavior-neutral:

- Every raw route, including GET and OPTIONS, now denies access without an explicit
  `permission()`, `publicRoute()` or `protectedByMiddleware()` declaration. The last
  helper must be paired with actual authentication middleware.
- Register routes during `rest_api_init`; use WordPress named regex captures such
  as `/(?P<id>\d+)`.
- Run `WpdbAtomicIdempotencyStore::installSchema()` to add the reservation-token
  column. The Woo registrar does this when its default idempotency store is enabled.
- Atomic reservations remain uncertain after exceptions by default. Do not opt
  into `releaseOnThrowable` without proving that a retry cannot repeat an effect.
- Woo writes validate complete payloads, reject unknown fields and use atomic
  idempotency when enabled. Product `price` is read-only; write regular/sale price.
- Native WP identity scopes cache/replay/rate-limit defaults. Persistent object
  cache rate limiting requires atomic increments; transient limiting uses MySQL
  named locks.

Follow the 1.1.1 coordination steps as well when upgrading directly from 1.0.x.

## Verification

Run `composer test`, `composer analyse` and `composer cs-check`. The optional smoke
plugins under `tests/smoke` are excluded from Composer release archives. Run them
only in an authorized test environment; they create and remove fixture records.
Keep credentials, deployment loaders and execution evidence outside the repository.

For each supported storage mode, verify namespace/URL isolation, identical and
concurrent replay, changed-payload conflict, mapped/unmapped identity restoration,
order status snapshots, address-only taxes, fractional stock configuration and
repeated payment completion. Inspect the resulting OpenAPI contract before
regenerating client code.
