
## Retrying subscription changes

`BillingManagement::subscribe`, `changePlan`, `cancel` and `reactivate` accept an optional trailing `idempotencyKey`. Persist a UUID with the customer's confirmed action before sending it; pass that same key for every retry of that action. A new action requires a new key. Keys contain 1–190 bytes. Never derive the key from a plan alone: a later legitimate purchase of the same plan is a different action.

The HTTP transport sends the key as `Idempotency-Key` to the existing public subscription endpoints. This is an additive `IdempotentSubscriptionTransport` capability. Older custom transports still work for existing calls without a key; asking an unsupported transport for idempotency throws `UnsupportedOperation` before sending anything. `FakeManagementTransport` carries the key through its normal scripted HTTP seam, so tests can inspect it.

Idempotency does not approve a price or replace preview. Show Billing's current change/cancellation preview, bind the confirmation to the organization, plan and user, and retain the action record when a response is lost. Do not report a timeout as a successful change.

Inject `Cbox\Billing\Client\Contracts\FeatureReader` for organization-specific grants. Its `features($org)` reads the public `GET /api/v1/entitlements/{org}/features` endpoint with the configured token. Call `value($featureKey)` for the resolved integer, string or boolean grant. Missing grants and suspended accounts return null. Transport errors remain explicit exceptions. Plan-level catalog values alone cannot reflect organization overrides.
