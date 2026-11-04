# One-off quotes

`BillingManagement` exposes the optional `OneOffQuoteTransport` contract:

- `createOneOffQuote(org, reference, product, idempotencyKey, currency: null, quantity: 1)`
- `oneOffQuote(org, id)` and `oneOffQuoteByReference(org, reference)`
- `acceptOneOffQuote(org, id, idempotencyKey)`
- `createOneOffQuotePaymentIntent(org, id)` → existing `PaymentIntent`
- `reconcileOneOffQuote(org, id)`
- `cancelOneOffQuote(org, id, idempotencyKey)`
- `refundInvoice(org, number, idempotencyKey, reason: 'requested', amountMinor: null)`

Quote calls return `OneOffQuote` with identifiers, reference, product, quantity, currency, `netMinor`, `taxMinor`, `totalMinor`, `priceVersion`, `expiresAt`, `status`, nullable invoice number and `paymentStatus`. Prices and taxes come only from Billing. Acceptance issues one invoice; only current invoice settlement (`paid`) or explicit zero-price acceptance (`not_required`) authorizes fulfilment.

The payment method reuses one durable intent; it never creates another on retry. Confirm its client secret in the gateway's official browser SDK, optionally with a saved payment method id. Poll the quote after confirmation. If a response or webhook is lost, reconciliation verifies the exact gateway intent; unknown outcomes must not be retried as a new purchase. Cancellation succeeds only after Billing proves the payment cannot settle and voids the invoice.

`InvoiceRefundResult` carries `status`, nullable `refundId` and nullable `approvalId`. Refund reasons are `requested`, `duplicate`, `service_issue`, `goodwill`, `order_cancelled`. Optional `amountMinor` is net, not gross. A refund can be `pending_approval`; poll current quote/invoice status until `refunded`, since an idempotency replay may still return the original pending response.

`FakeOneOffQuoteTransport` extends the regular management fake. Seed exact server answers with `withQuote`, `withAcceptedQuote`, `withQuotePayment` and `withInvoiceRefund`. It never fabricates prices, tax or settlement, and scopes every seeded answer to its organization. Existing custom transports remain compatible and fail explicitly when the new optional contract is unavailable.
