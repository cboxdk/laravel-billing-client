<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Contracts;

use Cbox\Billing\Client\Kept\KeptChanges;

/**
 * A {@see KeptQuestion} about one organization. When Billing says that organization changed
 * ({@see KeptChanges}), every answer kept about it before that moment is asked again instead
 * of served.
 */
interface OrganizationQuestion extends KeptQuestion
{
    /** The organization the question is about, or null when it is about none. */
    public function organization(): ?string;
}
