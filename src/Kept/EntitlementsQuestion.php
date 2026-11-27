<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Kept;

use Cbox\Billing\Client\Contracts\BillingTransport;
use Cbox\Billing\Client\Contracts\OrganizationQuestion;
use Cbox\Billing\Client\ValueObjects\Entitlement;
use Cbox\Billing\Client\ValueObjects\Entitlements;
use Illuminate\Contracts\Container\Container;

/**
 * "What may this organization use on each meter?" as a kept question. The answer is the
 * decoded `GET /v1/entitlements/{org}`, plan and overrides applied:
 * `['meters' => [meter => {enabled, allowance, weight, overage}]]`, with a null allowance for
 * an unlimited meter.
 */
readonly class EntitlementsQuestion implements OrganizationQuestion
{
    public function __construct(public string $organization) {}

    public function key(): string
    {
        return 'entitlements:'.$this->organization;
    }

    public function organization(): string
    {
        return $this->organization;
    }

    /** @return array{meters: array<string, array{enabled: bool, allowance: int|null, weight: float, overage: string}>} */
    public function ask(Container $app): array
    {
        return self::wrap($app->make(BillingTransport::class)->entitlements($this->organization));
    }

    public function accepts(mixed $value): bool
    {
        return self::parse($this->organization, $value) instanceof Entitlements;
    }

    /** @return array{meters: array<string, array{enabled: bool, allowance: int|null, weight: float, overage: string}>} */
    public static function wrap(Entitlements $entitlements): array
    {
        $meters = [];

        foreach ($entitlements->meters as $meter => $entitlement) {
            $meters[$meter] = [
                'enabled' => $entitlement->enabled,
                'allowance' => $entitlement->allowance,
                'weight' => $entitlement->weight,
                'overage' => $entitlement->overage,
            ];
        }

        return ['meters' => $meters];
    }

    /** The entitlements a kept answer holds, or null when it is not a valid answer. */
    public static function parse(string $organization, mixed $value): ?Entitlements
    {
        if (! is_array($value) || ! is_array($value['meters'] ?? null)) {
            return null;
        }

        $meters = [];

        foreach ($value['meters'] as $meter => $row) {
            if (! is_string($meter) || $meter === '' || ! is_array($row) || ! is_bool($row['enabled'] ?? null) || ! is_string($row['overage'] ?? null)) {
                return null;
            }

            $allowance = $row['allowance'] ?? null;
            $weight = $row['weight'] ?? null;

            if ((! is_int($allowance) && $allowance !== null) || (! is_int($weight) && ! is_float($weight))) {
                return null;
            }

            $meters[$meter] = new Entitlement($meter, $row['enabled'], $allowance, (float) $weight, $row['overage']);
        }

        return new Entitlements($organization, $meters);
    }
}
