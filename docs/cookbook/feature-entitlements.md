# Read feature grants

Feature flags and typed configuration values are separate from metered allowances. Read the live feature endpoint through `BillingClient::features($organization)`:

```php
try {
    $features = $billing->features($organization);
    $allowed = $features->enabled('cortex.autoscaling');
} catch (\Cbox\Billing\Client\Exceptions\TransportException) {
    $allowed = false;
}
```

Unknown keys and suspended accounts deny access. The transport validates real JSON booleans, feature types, value types and the account suspension flag. A malformed response, refusal or network failure throws; lease failure policies never grant feature access. Calls are not cached across requests or worker jobs. Applications may reuse a snapshot within one request.

`FeatureEntitlements::for($key)` returns the typed value object, including the `FeatureType`, optional integer/string config value and `FeatureSource`. Check `enabled($key)` before using its value. The transport implements the separate `FeatureTransport` contract, so existing custom metering transports remain source-compatible; a custom transport must implement it before calling `features()`.

The test transport runs through the same HTTP parser:

```php
$fake->feature('org_example', new FeatureEntitlement(
    key: 'cortex.autoscaling',
    type: FeatureType::Boolean,
    enabled: true,
    source: FeatureSource::Plan,
));
$fake->suspendAccount('org_example');
```

Feature definitions are published separately with `billing:sync-definitions`. Publishing a definition never grants it to an organization.

The published `FeatureReader` contract remains supported. `FeatureEntitlements` extends `OrganizationFeatures`, so `value($key)` returns the same enabled grant (`true` for a boolean, an integer or string for configuration), or `null` when absent, disabled or suspended. Both interfaces use one parser and one live snapshot. Older feature responses without source metadata retain `FeatureSource::Default`; invalid supplied metadata is rejected.

The fake also retains `setFeatures($organization, $values, $suspended)`. This replaces that organization’s feature set; `feature()` changes one entry and `suspendAccount()` changes its suspension state. Both APIs share the same state. Unconfigured organizations return an empty, denying snapshot.
