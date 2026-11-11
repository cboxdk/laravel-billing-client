<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Testing;

use Cbox\Billing\Client\Exceptions\BillingUnavailable;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\ValueObjects\LimitIncrease;
use Cbox\Billing\Client\ValueObjects\LimitIncreaseInput;
use Cbox\Billing\Client\ValueObjects\LimitIncreasePage;
use Cbox\Billing\Client\ValueObjects\ResourceLimit;
use DateTimeImmutable;
use InvalidArgumentException;

/** Scripted customer workflow. Never approves requests or simulates credit/risk decisions. */
trait InteractsWithResourceLimits
{
    /** @var array<string, array<string, ResourceLimit>> */
    private array $resourceCeilings = [];

    /** @var array<string, array<string, LimitIncrease>> */
    private array $resourceRequests = [];

    private int $resourceRequestSequence = 0;

    /** @param list<ResourceLimit> $limits */
    public function withResourceLimits(string $org, array $limits, ?string $product = null): static
    {
        $scope = $this->resourceScope($org, $product);
        $this->resourceCeilings[$scope] = [];
        foreach ($limits as $limit) {
            $this->resourceCeilings[$scope][$limit->key] = $limit;
        }

        return $this;
    }

    /** @return array<string, ResourceLimit> */
    public function resourceLimits(string $org, ?string $product = null): array
    {
        $this->resourceAvailability();

        return $this->resourceCeilings[$this->resourceScope($org, $product)] ?? throw TransportException::status('/limits', 503);
    }

    public function limitIncreaseRequests(string $org, int $page = 1, ?string $product = null): LimitIncreasePage
    {
        $this->resourceAvailability();
        if ($page < 1) {
            throw new InvalidArgumentException('The page must be positive.');
        }
        $rows = array_reverse(array_values($this->resourceRequests[$this->resourceScope($org, $product)] ?? []));

        return new LimitIncreasePage(array_slice($rows, ($page - 1) * 50, 50), $page, max(1, (int) ceil(count($rows) / 50)));
    }

    public function requestLimitIncrease(string $org, LimitIncreaseInput $request, ?string $product = null): LimitIncrease
    {
        $this->resourceAvailability();
        $scope = $this->resourceScope($org, $product);
        foreach ($this->resourceRequests[$scope] ?? [] as $existing) {
            if ($existing->requestKey === $request->requestKey) {
                if ($existing->feature !== $request->feature || $existing->requestedLimit !== $request->requestedLimit || $existing->reason !== $request->reason) {
                    throw TransportException::status('/limit-increase-requests', 422);
                }

                return $existing;
            }
            if ($existing->feature === $request->feature && $existing->status === 'pending') {
                throw TransportException::status('/limit-increase-requests', 422);
            }
        }
        $limit = $this->resourceLimits($org, $product)[$request->feature] ?? null;
        if ($limit === null || ! $limit->enabled || $request->requestedLimit <= $limit->limit) {
            throw TransportException::status('/limit-increase-requests', 422);
        }
        $id = FakePublicId::make('lir', ++$this->resourceRequestSequence);

        return $this->resourceRequests[$scope][$id] = new LimitIncrease($id, $request->requestKey, $request->feature, 'pending',
            $limit->limit, $request->requestedLimit, $request->reason, createdAt: new DateTimeImmutable);
    }

    public function cancelLimitIncrease(string $org, string $requestId, ?string $product = null): LimitIncrease
    {
        $this->resourceAvailability();
        if (trim($requestId) === '') {
            throw new InvalidArgumentException('The request id must not be empty.');
        }
        $scope = $this->resourceScope($org, $product);
        $request = $this->resourceRequests[$scope][$requestId] ?? throw TransportException::status('/limit-increase-requests', 404);
        if (! in_array($request->status, ['pending', 'cancelled'], true)) {
            throw TransportException::status('/limit-increase-requests', 422);
        }

        return $this->resourceRequests[$scope][$requestId] = new LimitIncrease($request->id, $request->requestKey, $request->feature,
            'cancelled', $request->observedLimit, $request->requestedLimit, $request->reason, createdAt: $request->createdAt);
    }

    private function resourceAvailability(): void
    {
        if ($this->down) {
            throw new BillingUnavailable('The fake billing service is down.');
        }
        if ($this->refusal !== null) {
            throw TransportException::status('/limits', $this->refusal);
        }
    }

    private function resourceScope(string $org, ?string $product): string
    {
        if (trim($org) === '' || ($product !== null && trim($product) === '')) {
            throw new InvalidArgumentException('Organization and any supplied product must be non-empty.');
        }

        return json_encode([$org, $product], JSON_THROW_ON_ERROR);
    }
}
