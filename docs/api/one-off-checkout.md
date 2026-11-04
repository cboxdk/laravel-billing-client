# One-off checkout

Use `BillingManagement::createOneOffCheckoutSession($org, $items, $returnUrl, $reference, $idempotencyKey, $currency)` with a list of `OneOffCheckoutItem` values. Each item names a Billing catalog product and quantity; Billing owns prices and tax.

Use a stable idempotency key and exact request body when retrying. `OneOffCheckoutSession::url` is nullable: Billing redacts the bearer URL on replay. A missing URL is not permission to start another order. `expiresAt` remains available. Payment success is established through Billing settlement events, never the browser return.

`reference` correlates the external order within its organization. It requires the Billing server change that carries standalone checkout references through purchases and `product.purchased`; older servers accepted but discarded it. It is not an authorization credential or an idempotency key.

The optional `OneOffCheckoutTransport` leaves existing custom management transports compatible. Unsupported transports fail explicitly. The official fake supports `withOneOffProduct($key)`, organization-separated replay, unknown-product rejection and changed-request conflicts; it does not simulate financial settlement.
