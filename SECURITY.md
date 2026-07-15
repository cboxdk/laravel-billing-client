# Security Policy

`cboxdk/laravel-billing-client` is the SDK a product embeds to enforce usage limits
and report usage against a Cbox Billing service. It runs on the request hot path and
holds a bearer credential to that service, so we take reports seriously.

## Reporting a vulnerability

**Do not open a public issue for a security vulnerability.**

Report privately through **GitHub Private Vulnerability Reporting**:
[Report a vulnerability](https://github.com/cboxdk/laravel-billing-client/security/advisories/new)
(repository → **Security** → **Report a vulnerability**).

Please include the affected version/commit, a description and impact, reproduction
steps or a proof of concept, and any suggested remediation.

## What to expect

This is a pre-1.0, open-source project maintained on a best-effort basis. We will
respond as promptly as we can, keep you informed while we investigate, and (unless
you prefer to stay anonymous) credit you when a fix ships. We coordinate the timing
of any public disclosure with you. We do not operate a paid bug-bounty or a
guaranteed response-time SLA.

## Safe harbor

We will not pursue or support legal action against anyone who, in good faith,
reports a vulnerability through the private channel above, avoids privacy violations
and service degradation, only interacts with systems they own or are permitted to
test, and gives us reasonable time to remediate before public disclosure.

## Supported versions

During the pre-1.0 period, only the latest tagged release is supported.

| Version | Supported |
|---------|-----------|
| latest `0.x` | ✅ |
| older `0.x` | ❌ |

## Security posture (honest scope)

This package is a **client**, not an authority — the Cbox Billing service is the
source of truth; the SDK enforces a leased slice locally and reports usage.

- **Deny-by-default on hard limits.** When a hard-limited meter can neither be leased
  nor reached, enforcement fails **closed** (`QuotaExceeded`); the fail-open/closed
  policy for infrastructure faults is configurable and defaults conservatively.
- **Bounded, documented overshoot.** Pessimistic leasing means outstanding leases can
  never exceed the central allowance; the accepted overshoot is `lease_size × nodes`
  and is documented, not hidden.
- **Credential handling is the host's responsibility.** The bearer token
  (`billing-client.api_token`) is read from configuration — keep it in an environment
  variable / secret store, never commit it. The SDK sends it only to the configured
  `base_url` over the transport you configure (use HTTPS).
- **No cryptography is implemented here.** Transport security is TLS provided by your
  HTTP client; the SDK adds no bespoke crypto.
- **Supply chain.** Every push runs the CI gate (PHPStan max, Pest, `composer audit`,
  permissive-license check) and ships a CycloneDX SBOM.

We do not claim conformance to any standard this package does not implement, and this
policy lists no security contact, key, or SLA that does not exist.
