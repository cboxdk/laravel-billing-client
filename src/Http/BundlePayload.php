<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\ActiveBundle;
use Cbox\Billing\Client\ValueObjects\BundleBenefit;
use Cbox\Billing\Client\ValueObjects\BundleConsequence;
use Cbox\Billing\Client\ValueObjects\BundleOffer;
use Cbox\Billing\Client\ValueObjects\BundleOfferPlan;
use Cbox\Billing\Client\ValueObjects\BundleSaving;
use Cbox\Billing\Client\ValueObjects\CancelPreview;
use Cbox\Billing\Client\ValueObjects\OrganizationBundles;
use Cbox\Billing\Client\ValueObjects\SubscriptionBundle;
use DateTimeImmutable;
use Throwable;

/**
 * Reads the bundle shapes of the management API into value objects, for
 * {@see HttpManagementTransport}. Deny-by-default like the transport: a required field that is
 * missing or of the wrong type is a malformed response, never a silent default. An absent optional
 * block (an older server) reads as null or an empty list.
 */
class BundlePayload
{
    public static function subscriptionBundle(mixed $raw, string $path): ?SubscriptionBundle
    {
        if (! is_array($raw)) {
            return null;
        }

        return new SubscriptionBundle(
            benefit: self::benefit($raw['benefit'] ?? null, $path),
            endsAt: self::date($raw['ends_at'] ?? null),
            next: self::benefit($raw['next'] ?? null, $path),
            nextFrom: self::date($raw['next_from'] ?? null),
        );
    }

    public static function benefit(mixed $raw, string $path): ?BundleBenefit
    {
        if (! is_array($raw)) {
            return null;
        }

        return new BundleBenefit(
            rule: self::string($raw, 'rule', $path),
            kind: self::string($raw, 'kind', $path),
            label: self::string($raw, 'label', $path),
            priceBeforeMinor: self::int($raw, 'price_before_minor', $path),
            priceAfterMinor: self::int($raw, 'price_after_minor', $path),
            currency: self::string($raw, 'currency', $path),
            name: self::optional($raw, 'name') ?? '',
            description: self::optional($raw, 'description') ?? '',
            text: self::optional($raw, 'text') ?? '',
            percentOff: self::nullableInt($raw, 'percent_off', $path),
            amountOffMinor: self::nullableInt($raw, 'amount_off_minor', $path),
            priceBefore: self::optional($raw, 'price_before') ?? '',
            priceAfter: self::optional($raw, 'price_after') ?? '',
            requires: self::optional($raw, 'requires'),
        );
    }

    /** @return list<BundleConsequence> */
    public static function consequences(mixed $raw, string $path): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $from = self::date($item['from'] ?? null) ?? throw TransportException::malformed($path, 'consequence without a from date');

            $out[] = new BundleConsequence(
                lost: ($item['lost'] ?? false) === true,
                from: $from,
                message: self::string($item, 'message', $path),
                priceBeforeMinor: self::int($item, 'price_before_minor', $path),
                priceAfterMinor: self::int($item, 'price_after_minor', $path),
                currency: self::string($item, 'currency', $path),
                subscription: self::optional($item, 'subscription'),
                product: self::optional($item, 'product'),
                productName: self::optional($item, 'product_name'),
                plan: self::optional($item, 'plan'),
                before: self::benefit($item['before'] ?? null, $path),
                after: self::benefit($item['after'] ?? null, $path),
                priceBefore: self::optional($item, 'price_before') ?? '',
                priceAfter: self::optional($item, 'price_after') ?? '',
            );
        }

        return $out;
    }

    /** @param  array<array-key, mixed>  $raw */
    public static function cancelPreview(array $raw, string $path): CancelPreview
    {
        return new CancelPreview(
            mode: self::string($raw, 'mode', $path),
            endsAt: self::date($raw['ends_at'] ?? null),
            consequences: self::consequences($raw['consequences'] ?? null, $path),
            subscription: self::optional($raw, 'subscription'),
        );
    }

    /** @param  array<array-key, mixed>  $raw */
    public static function organizationBundles(array $raw, string $path): OrganizationBundles
    {
        $active = [];

        foreach (self::list($raw['active'] ?? null, $path, 'active') as $item) {
            $bundle = self::subscriptionBundle($item, $path);

            if ($bundle !== null) {
                $active[] = new ActiveBundle($bundle, self::optional($item, 'subscription'), self::optional($item, 'product'), self::optional($item, 'plan'));
            }
        }

        $offers = [];

        foreach (self::list($raw['offers'] ?? null, $path, 'offers') as $item) {
            $offers[] = self::offer($item, $path);
        }

        return new OrganizationBundles(
            organization: self::string($raw, 'organization', $path),
            currency: self::string($raw, 'currency', $path),
            locale: self::string($raw, 'locale', $path),
            active: $active,
            offers: $offers,
            organizationRef: self::optional($raw, 'organization_ref'),
            product: self::optional($raw, 'product'),
        );
    }

    /** @param  array<array-key, mixed>  $raw */
    private static function offer(array $raw, string $path): BundleOffer
    {
        $plans = [];

        foreach (is_array($raw['plans'] ?? null) ? $raw['plans'] : [] as $plan) {
            if (is_array($plan)) {
                $plans[] = new BundleOfferPlan(
                    plan: self::string($plan, 'plan', $path),
                    name: self::string($plan, 'name', $path),
                    priceBeforeMinor: self::int($plan, 'price_before_minor', $path),
                    priceAfterMinor: self::int($plan, 'price_after_minor', $path),
                    currency: self::string($plan, 'currency', $path),
                    checkoutUrl: self::string($plan, 'checkout_url', $path),
                    interval: self::optional($plan, 'interval') ?? 'month',
                    priceBefore: self::optional($plan, 'price_before') ?? '',
                    priceAfter: self::optional($plan, 'price_after') ?? '',
                );
            }
        }

        $products = [];

        foreach (is_array($raw['products_to_add'] ?? null) ? $raw['products_to_add'] : [] as $product) {
            if (is_array($product) && is_string($product['product'] ?? null)) {
                $products[$product['product']] = self::optional($product, 'name') ?? $product['product'];
            }
        }

        $amountOff = null;

        if (is_array($raw['amount_off'] ?? null)) {
            $amountOff = [];

            foreach ($raw['amount_off'] as $currency => $minor) {
                if (is_int($minor)) {
                    $amountOff[(string) $currency] = $minor;
                }
            }
        }

        $saves = $raw['saves'] ?? null;

        return new BundleOffer(
            rule: self::string($raw, 'rule', $path),
            kind: self::string($raw, 'kind', $path),
            headline: self::string($raw, 'headline', $path),
            label: self::optional($raw, 'label') ?? '',
            description: self::optional($raw, 'description') ?? '',
            name: self::optional($raw, 'name') ?? '',
            percentOff: self::nullableInt($raw, 'percent_off', $path),
            amountOff: $amountOff,
            product: self::optional($raw, 'product'),
            productName: self::optional($raw, 'product_name'),
            discountedProduct: self::optional($raw, 'discounted_product'),
            plans: $plans,
            saves: is_array($saves) ? new BundleSaving(
                priceBeforeMinor: self::int($saves, 'price_before_minor', $path),
                priceAfterMinor: self::int($saves, 'price_after_minor', $path),
                subscription: self::optional($saves, 'subscription'),
                product: self::optional($saves, 'product'),
                plan: self::optional($saves, 'plan'),
                from: self::date($saves['from'] ?? null),
                priceBefore: self::optional($saves, 'price_before') ?? '',
                priceAfter: self::optional($saves, 'price_after') ?? '',
            ) : null,
            minPaidProducts: self::nullableInt($raw, 'min_paid_products', $path),
            paidProductsHeld: self::nullableInt($raw, 'paid_products_held', $path),
            productsNeeded: self::nullableInt($raw, 'products_needed', $path),
            currentPercentOff: self::nullableInt($raw, 'current_percent_off', $path),
            productsToAdd: $products,
        );
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $raw, string $path, string $key): array
    {
        if (! is_array($raw)) {
            throw TransportException::malformed($path, "missing {$key} array");
        }

        return array_values(array_filter($raw, 'is_array'));
    }

    /** @param  array<array-key, mixed>  $raw */
    private static function string(array $raw, string $key, string $path): string
    {
        $value = $raw[$key] ?? null;

        if (! is_string($value)) {
            throw TransportException::malformed($path, "missing or non-string {$key}");
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $raw */
    private static function optional(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param  array<array-key, mixed>  $raw */
    private static function int(array $raw, string $key, string $path): int
    {
        $value = $raw[$key] ?? null;

        if (! is_int($value)) {
            throw TransportException::malformed($path, "missing or non-integer {$key}");
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $raw */
    private static function nullableInt(array $raw, string $key, string $path): ?int
    {
        return ($raw[$key] ?? null) === null ? null : self::int($raw, $key, $path);
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
