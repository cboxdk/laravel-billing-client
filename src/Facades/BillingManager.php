<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Facades;

use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\ValueObjects\ChangePreview;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionResult;
use Cbox\Billing\Client\ValueObjects\UsageSummary;
use Illuminate\Support\Facades\Facade;

/**
 * Facade over the {@see BillingManagement} self-service client.
 *
 * @method static list<\Cbox\Billing\Client\ValueObjects\Plan> plans()
 * @method static ?Subscription subscription(string $org)
 * @method static SubscriptionResult subscribe(string $org, string $plan)
 * @method static ChangePreview previewChange(string $org, string $plan)
 * @method static Subscription changePlan(string $org, string $plan)
 * @method static Subscription cancel(string $org, bool $atPeriodEnd = false)
 * @method static UsageSummary usage(string $org)
 * @method static list<\Cbox\Billing\Client\ValueObjects\Invoice> invoices(string $org)
 *
 * @see BillingManagement
 */
class BillingManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BillingManagement::class;
    }
}
