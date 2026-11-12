<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Kept;

use Cbox\Billing\Client\BillingClient;
use Cbox\Billing\Client\Contracts\KeptAnswers;
use Cbox\Billing\Client\Exceptions\BillingClientException;
use Cbox\Billing\Client\ValueObjects\FeatureEntitlements;

/**
 * An organization's features (plan grants and overrides), read from a kept answer
 * ({@see KeptAnswers}) so that a page that shows or gates on a feature never waits on Billing:
 *
 *     $features = $kept->features($organization->cbox_id);               // a page
 *     $features = $kept->features($organization->cbox_id, acting: true);  // before a change
 *
 * Same answer as {@see BillingClient::features()}. The kept-answer rules decide when Billing
 * is asked; {@see KeptChanges} makes a webhook about the organization or a plan count.
 */
class KeptFeatures
{
    public function __construct(private readonly KeptAnswers $answers) {}

    /**
     * @throws BillingClientException when Billing must be asked and cannot answer
     */
    public function features(string $organization, bool $acting = false): FeatureEntitlements
    {
        return FeaturesQuestion::parse($organization, $this->answers->answer(new FeaturesQuestion($organization), $acting))
            ?? new FeatureEntitlements($organization);
    }

    /**
     * Ask Billing now and keep the answer.
     *
     * @throws BillingClientException when Billing cannot answer
     */
    public function refresh(string $organization): FeatureEntitlements
    {
        return FeaturesQuestion::parse($organization, $this->answers->refresh(new FeaturesQuestion($organization)))
            ?? new FeatureEntitlements($organization);
    }

    /** Drop the kept features: the next read asks Billing. */
    public function forget(string $organization): void
    {
        $this->answers->forget(new FeaturesQuestion($organization));
    }
}
