<?php

declare(strict_types=1);

use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Billing\Client\Http\HttpManagementTransport;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

it('collects every page without following cursor URLs', function (string $method, string $endpoint, array $row, string $field): void {
    $cursor = 'https://attacker.test/steal?token=a&x=b';
    Http::fakeSequence()->push(['data' => [$row], 'has_more' => true, 'next_cursor' => $cursor])
        ->push(['data' => [array_replace($row, [$field => 'second'])], 'has_more' => false, 'next_cursor' => null]);

    $items = (new HttpManagementTransport(app(Factory::class), 'https://billing.test', 'secret-token'))->{$method}('org/a');
    expect($items)->toHaveCount(2)->and($items[1]->{$field})->toBe('second');
    $requests = Http::recorded();
    expect($requests[0][0]->url())->toBe('https://billing.test/api/v1/'.$endpoint.'/org%2Fa');
    expect($requests[1][0]->url())->toBe('https://billing.test/api/v1/'.$endpoint.'/org%2Fa?'.http_build_query(['cursor' => $cursor]));
})->with([
    'invoices' => ['invoices', 'invoices', ['number' => 'first', 'amount_minor' => 100], 'number'],
    'cards' => ['paymentMethods', 'payment-methods', ['id' => 'first'], 'id'],
]);

it('rejects incomplete or malformed pagination instead of returning partial collections', function (array $tail): void {
    Http::fakeSequence()->push(array_replace(['data' => [['id' => 'first']], 'has_more' => true], $tail));
    expect(fn () => (new HttpManagementTransport(app(Factory::class), 'https://billing.test', 'secret-token'))->paymentMethods('org_a'))->toThrow(TransportException::class);
})->with([
    'missing cursor' => [[]],
    'empty cursor' => [['next_cursor' => '']],
    'nonstring cursor' => [['next_cursor' => 12]],
    'nonboolean more' => [['has_more' => 'true']],
    'null more' => [['has_more' => null]],
    'malformed row' => [['data' => ['invalid'], 'has_more' => false]],
]);

it('rejects repeated cursors', function (): void {
    Http::fakeSequence()->push(['data' => [], 'has_more' => true, 'next_cursor' => 'same'])
        ->push(['data' => [], 'has_more' => true, 'next_cursor' => 'same']);
    expect(fn () => (new HttpManagementTransport(app(Factory::class), 'https://billing.test', 'secret-token'))->invoices('org_a'))->toThrow(TransportException::class);
    Http::assertSentCount(2);
});

it('bounds endless unique pagination', function (): void {
    $page = 0;
    Http::fake(function () use (&$page) {
        return Http::response(['data' => [], 'has_more' => true, 'next_cursor' => 'page-'.++$page]);
    });
    expect(fn () => (new HttpManagementTransport(app(Factory::class), 'https://billing.test', 'secret-token'))->paymentMethods('org_a'))->toThrow(TransportException::class);
    Http::assertSentCount(1000);
});
