# Upgrading Better Route

The Composer package version and your REST namespace version are independent.
Updating the library does not rename your `myapp/v1` routes. Treat changes to your
own response schemas and permissions as changes to your application's API contract.

## 1.1.2 to 1.1.3

Install the security patch while keeping PHP 8.1/8.2 support:

```bash
composer require better-route/better-route:^1.1.3
composer check-platform-reqs
```

`OwnedResourcePolicy` now authorizes the URL-captured `id`, matching the Resource
handler. On affected older versions, an authenticated caller could supply an owned
query/body ID while targeting a different URL object. The impact depends on the
configured repository and policy, particularly custom-table CRUD. Upgrade applications
using this policy; arbitrary custom policies are not automatically repaired.

For custom callbacks, resolve the target from `$request->get_url_params()['id']`
instead of the merged `get_param('id')`. Keep authentication, action/field policies
and list-query ownership filters in place. No database, cache-key or idempotency-key
migration is introduced. Roll out the patch to all web and worker processes.

Version 2.0 contains the same fix and additionally requires PHP 8.3. Select that
major version deliberately after upgrading every runtime.

## 1.1.1 to 1.1.2

- OpenAPI remains PHP 8.1-compatible and now validates generated documents in CI.
  PATCH operation IDs change from `...Update` to `...Patch` to distinguish them from
  PUT. Regenerate clients if they consumed the previous duplicate IDs. Multiple Woo
  registrations can supply `operationIdPrefix`.
- Duplicate operations/IDs, equivalent path templates, malformed security and
  undefined security schemes now raise configuration errors. Use strict schemas
  before release, provide referenced components and exclude unsupported PCRE paths.
  Runtime checks do not fetch external schema references or replace full validation.
- Customer updates have a separate input schema without username. Metadata inputs
  document list/map/null forms; coupon schemas allow registered custom discount types
  and native date clearing. `ErrorResponse` describes native WordPress errors too.
- Coupon internal metadata keys are rejected, preventing `meta_data.usage_count`
  and other internal keys from invoking native setters. Use supported top-level
  fields; usage counts remain read-only. Public custom metadata remains writable.
- Empty Analytics metric maps serialize as JSON objects instead of arrays. Attach
  `WooAnalyticsOpenApi::args()` and `::meta()` to manually registered report routes;
  helper schemas are included in `wooOpenApiComponents()`.

- Customer routes still default to the `customer` role, with stronger exclusion of
  privileged multi-role accounts, direct administrative capabilities and super
  administrators. Those objects can now return 404 where older code returned them.
  Configure `CustomerRolePolicy` explicitly to include other customer-like roles.
  `allowPrivilegedRoles: true` is a server-side opt-in; request filters cannot enable it.
- Customer metadata writes reject known WordPress role/capability/session keys even
  when they do not start with an underscore. Reads omit those keys. Review third-party
  authorization metadata separately and apply an application allowlist.
- Coupon default read DTOs gain `state` and omit `meta_data`. Trusted callers requiring
  public plugin metadata must request the field explicitly. State is a management
  summary, not a checkout-validity decision. Existing unfiltered lists remain published
  coupons, including expired/exhausted coupons; filtering is opt-in.
- Analytics is opt-in through `WooAnalyticsService`; existing registrar route sets are
  unchanged. Native report capability and historical import coverage are mandatory.
  The wrapper does not initialize imports or add a cross-user response cache.
- PHPStan analysis now has a 3 GB memory limit and at most two workers for the large
  WordPress/WooCommerce stubs. The runtime smoke job provisions disposable MySQL/WP/Woo.

PHP minimum remains 8.1 and no new database migration is required. The planned later
PHP 8.3 minimum is a separate development line. Keep existing 1.1.1 identity/idempotency
rollout requirements when upgrading from an older version.

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
