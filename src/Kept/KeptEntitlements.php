<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Kept;

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Contracts\KeptAnswers;
use Cbox\Billing\Client\Exceptions\BillingClientException;
use Cbox\Billing\Client\ValueObjects\Entitlements;

/**
 * An organization's meter entitlements (allowance, overage and weight per meter, plan and
 * overrides applied), read from a kept answer ({@see KeptAnswers}) so that a page that shows a
 * limit, or a request that falls back to one, never waits on Billing:
 *
 *     $calls = $kept->entitlements($organization->cbox_id)->for('brain.calls');               // a page
 *     $calls = $kept->entitlements($organization->cbox_id, acting: true)->for('brain.calls');  // before a change
 *
 * Same answer as {@see BillingClient::entitlements()}. This is what to show and check limits
 * against, never a plan copied into the app: it carries the organization's overrides. The
 * kept-answer rules decide when Billing is asked; {@see KeptChanges} makes a webhook about the
 * organization or a plan count. Metering itself still goes through {@see BillingClient::reserve()}.
 */
class KeptEntitlements
{
    public function __construct(private readonly KeptAnswers $answers) {}

    /**
     * @throws BillingClientException when Billing must be asked and cannot answer
     */
    public function entitlements(string $organization, bool $acting = false): Entitlements
    {
        return EntitlementsQuestion::parse($organization, $this->answers->answer(new EntitlementsQuestion($organization), $acting))
            ?? new Entitlements($organization, []);
    }

    /**
     * Ask Billing now and keep the answer.
     *
     * @throws BillingClientException when Billing cannot answer
     */
    public function refresh(string $organization): Entitlements
    {
        return EntitlementsQuestion::parse($organization, $this->answers->refresh(new EntitlementsQuestion($organization)))
            ?? new Entitlements($organization, []);
    }

    /** Drop the kept entitlements: the next read asks Billing. */
    public function forget(string $organization): void
    {
        $this->answers->forget(new EntitlementsQuestion($organization));
    }
}
