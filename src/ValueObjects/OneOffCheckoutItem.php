<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/** A Billing catalog key and quantity; the server owns its price and tax. */
readonly class OneOffCheckoutItem
{
    public function __construct(public string $product, public int $quantity = 1) {}

    /** @return array{product: string, quantity: int} */
    public function toArray(): array
    {
        return ['product' => $this->product, 'quantity' => $this->quantity];
    }
}
