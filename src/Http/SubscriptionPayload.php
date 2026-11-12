<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

use Cbox\Billing\Client\Enums\InvoicePayment;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Kept\KeptSubscriptions;
use Cbox\Billing\Client\ValueObjects\ChargedInvoice;
use Cbox\Billing\Client\ValueObjects\PendingChange;
use Cbox\Billing\Client\ValueObjects\PriceTax;
use Cbox\Billing\Client\ValueObjects\Subscription;
use Cbox\Billing\Client\ValueObjects\SubscriptionDiscount;
use DateTimeImmutable;
use DateTimeInterface;
use Throwable;

/**
 * The management API's subscription shape, both ways: {@see Subscription()} reads it for
 * {@see HttpManagementTransport}, and {@see toArray()} writes a {@see Subscription} back in the
 * same wire shape, so {@see KeptSubscriptions} can keep it as plain data and read it back
 * through the very same parser.
 */
class SubscriptionPayload
{
    private const string DATE = 'Y-m-d\TH:i:s.uP';

    /**
     * @param  array<array-key, mixed>  $raw
     *
     * @throws TransportException when a required field is missing (malformed)
     */
    public static function subscription(array $raw, string $path): Subscription
    {
        return new Subscription(
            plan: self::stringField($raw, 'plan', $path),
            status: is_string($raw['status'] ?? null) ? $raw['status'] : 'active',
            periodStart: self::date($raw['period_start'] ?? null),
            periodEnd: self::date($raw['period_end'] ?? null),
            renewsAt: self::date($raw['renews_at'] ?? null),
            discount: self::discount($raw['discount'] ?? null, $path),
            organizationRef: self::nullableString($raw, 'organization_ref'),
            pauseReason: self::nullableString($raw, 'pause_reason'),
            paused: ($raw['paused'] ?? false) === true,
            trialEndsAt: self::date($raw['trial_ends_at'] ?? null),
            pendingChange: self::pendingChange($raw['pending_change'] ?? null),
            bundle: BundlePayload::subscriptionBundle($raw['bundle'] ?? null, $path),
            recurring: TaxPayload::recurring($raw['recurring'] ?? null),
            id: WireId::from($raw['id'] ?? null) ?? '',
        );
    }

    /**
     * The invoice a subscribe or an immediate plan change charged (0.6.3), or null when nothing
     * was billed or the server predates the field. An invoice without its `id` is malformed, not
     * absent. A `payment` this client does not know reads as pending: the customer may have to act.
     *
     * @throws TransportException when the invoice is malformed
     */
    public static function chargedInvoice(mixed $raw, string $path): ?ChargedInvoice
    {
        if (! is_array($raw)) {
            return null;
        }

        $id = WireId::from($raw['id'] ?? null) ?? throw TransportException::malformed($path, 'charged invoice without an id');
        $amount = $raw['amount_due_minor'] ?? null;
        $payment = $raw['payment'] ?? null;

        return new ChargedInvoice(
            id: $id,
            number: self::nullableString($raw, 'number'),
            status: is_string($raw['status'] ?? null) ? $raw['status'] : 'open',
            amountDueMinor: is_int($amount) ? $amount : throw TransportException::malformed($path, 'charged invoice without amount_due_minor'),
            currency: is_string($raw['currency'] ?? null) ? $raw['currency'] : '',
            payment: (is_string($payment) ? InvoicePayment::tryFrom($payment) : null) ?? InvoicePayment::Pending,
        );
    }

    /**
     * The bound coupon, or null when there is none (or the server predates the field). A
     * discount object without its `code` or `duration` is malformed, not absent.
     *
     * @throws TransportException when the discount is malformed
     */
    public static function discount(mixed $raw, string $path): ?SubscriptionDiscount
    {
        if (! is_array($raw)) {
            return null;
        }

        return new SubscriptionDiscount(
            code: self::stringField($raw, 'code', $path),
            duration: self::stringField($raw, 'duration', $path),
            name: self::nullableString($raw, 'name'),
            percentOff: ($raw['percent_off'] ?? null) === null ? null : self::intField($raw, 'percent_off', $path),
            amountOffMinor: ($raw['amount_off_minor'] ?? null) === null ? null : self::intField($raw, 'amount_off_minor', $path),
            currency: self::nullableString($raw, 'currency'),
            endsAt: self::date($raw['ends_at'] ?? null),
        );
    }

    /** A scheduled plan change, or null when none is scheduled (or the server predates it). */
    public static function pendingChange(mixed $raw): ?PendingChange
    {
        if (! is_array($raw) || ! is_string($raw['plan'] ?? null)) {
            return null;
        }

        return new PendingChange($raw['plan'], self::date($raw['effective_at'] ?? null));
    }

    /**
     * A subscription in the wire shape {@see Subscription()} reads.
     *
     * @return array<string, mixed>
     */
    public static function toArray(Subscription $subscription): array
    {
        $discount = $subscription->discount;
        $recurring = $subscription->recurring;

        return [
            'id' => $subscription->id === '' ? null : $subscription->id,
            'plan' => $subscription->plan,
            'status' => $subscription->status,
            'period_start' => self::format($subscription->periodStart),
            'period_end' => self::format($subscription->periodEnd),
            'renews_at' => self::format($subscription->renewsAt),
            'discount' => $discount === null ? null : [
                'code' => $discount->code,
                'duration' => $discount->duration,
                'name' => $discount->name,
                'percent_off' => $discount->percentOff,
                'amount_off_minor' => $discount->amountOffMinor,
                'currency' => $discount->currency,
                'ends_at' => self::format($discount->endsAt),
            ],
            'organization_ref' => $subscription->organizationRef,
            'pause_reason' => $subscription->pauseReason,
            'paused' => $subscription->paused,
            'trial_ends_at' => self::format($subscription->trialEndsAt),
            'pending_change' => $subscription->pendingChange === null ? null : [
                'plan' => $subscription->pendingChange->plan,
                'effective_at' => self::format($subscription->pendingChange->effectiveAt),
            ],
            'bundle' => $subscription->bundle?->toArray(),
            'recurring' => $recurring === null ? null : [
                'net_minor' => $recurring->netMinor,
                'currency' => $recurring->currency,
                'tax' => $recurring->tax instanceof PriceTax ? $recurring->tax->toArray() : null,
            ],
        ];
    }

    private static function format(?DateTimeInterface $date): ?string
    {
        return $date?->format(self::DATE);
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

    /** @param  array<array-key, mixed>  $body */
    private static function stringField(array $body, string $key, string $path): string
    {
        $value = $body[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw TransportException::malformed($path, "missing string field [{$key}]");
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $body */
    private static function intField(array $body, string $key, string $path): int
    {
        $value = $body[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && is_numeric($value))) {
            throw TransportException::malformed($path, "missing integer field [{$key}]");
        }

        return (int) $value;
    }

    /** @param  array<array-key, mixed>  $body */
    private static function nullableString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
