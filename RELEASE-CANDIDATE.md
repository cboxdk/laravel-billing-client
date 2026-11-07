# laravel-billing-client 0.4.14

Status: prepared local candidate. No public tag, registry publication or deployment has occurred.

Publish definition catalogs automatically without assigning grants; expose typed live feature decisions and preserve OrganizationFeatures compatibility.

Invoice and payment-method reads now consume every cursor page from the same endpoint. Malformed, repeated or excessive cursors fail explicitly instead of returning partial financial records.

## Upgrade requirements

Configure definition publication credentials and run the SDK definition sync command during deployment. Publication creates or updates definitions only; entitlements and grants stay manual. Existing FeatureReader consumers retain value() and suspended behavior through the compatible base object.

Dependency installation against public registries remains contingent on the approved release cascade. Local artifact checks prove the enclosed contents only; they do not claim public indexing.
