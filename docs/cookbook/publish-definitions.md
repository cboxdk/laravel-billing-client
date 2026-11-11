---
title: Publish product definitions
weight: 60
description: Synchronize an app's features and meters without assigning customer access.
---

# Publish product definitions

Keep `billing-definitions.json` in the application repository. It declares the complete
set of features and meters owned by this product. Billing uses it to populate its catalog;
an operator still chooses which plans and customers receive each feature or allowance.

```json
{
  "version": "2026-11-06.1",
  "features": [
    {"key": "sites.custom_domains", "name": "Custom domains", "type": "boolean"}
  ],
  "meters": [
    {"key": "sites.pages", "name": "Pages", "unit": "pages", "aggregation": "sum"}
  ]
}
```

Both lists are required, even when empty. Keys start with the product's Billing key and
a dot. Unknown fields, including pricing, plan and assignment fields, are rejected.

Configure `BILLING_CLIENT_API_URL` and `BILLING_CLIENT_DEFINITION_TOKEN` in the deployment's
secret store. The definition token must be bound to this product and environment and hold
`definitions:write`. It is separate from the runtime enforcement credential.

Run this required deployment step after migrations and configuration are available:

```bash
php artisan billing:sync-definitions
```

An alternative path can be passed as the command's argument. A missing or invalid file,
unavailable Billing service, refusal, or incomplete acknowledgement returns a nonzero
exit code. Stop the deployment on failure. Repeating the command reconciles the whole
declaration; it never grants access or changes customer pricing.

Display names and descriptions can change. A feature's type and a meter's measurement
semantics cannot change under an existing key: publish a new key for a different measure.
Removing a declaration retires it without deleting its catalog entry or changing existing
grants. Restoring the key reuses that entry. Billing refuses to claim a key already managed
manually or owned by another product.

For an application test, bind `Contracts\DefinitionTransport` to
`Testing\FakeDefinitionTransport`. Its `publications` list contains the typed
`Definitions\DefinitionManifest` documents it received; set `refusal` to exercise a failed
publication. The transport interface also permits a deployment-specific publisher without
changing the command.

Identity roles and permissions are declared separately through Cbox ID. Neither a catalog
entry nor a paid feature grants a person permission to read or change a resource. Apps must
check the person's current permissions and the resource's tenant and sharing rules.


Use `php artisan billing:sync-definitions --validate-only` in CI to validate the local file
without credentials or network calls. Deployment must run the command without that option so
Billing validates semantics and acknowledges every key. Existing manually maintained catalog
entries require an explicit operator handover in Billing before the first publication.
