<?php

declare(strict_types=1);

use Cbox\Billing\Client\Buffers\DatabaseUsageBuffer;
use Cbox\Billing\Client\ValueObjects\CumulativeUsage;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    Schema::create('billing_client_usage', function (Blueprint $table): void {
        $table->id();
        $table->string('org');
        $table->string('meter');
        $table->unsignedBigInteger('cumulative')->default(0);
        $table->unsignedBigInteger('seq')->default(0);
        $table->timestamp('updated_at')->nullable();
        $table->unique(['org', 'meter']);
    });
});

afterEach(function (): void {
    Schema::dropIfExists('billing_client_usage');
});

function databaseBuffer(): DatabaseUsageBuffer
{
    return new DatabaseUsageBuffer(app(ConnectionResolverInterface::class));
}

it('durably accumulates a monotonic cumulative total per meter', function (): void {
    $buffer = databaseBuffer();

    $buffer->record('org_a', 'api.calls', 10);
    $buffer->record('org_a', 'api.calls', 5);
    $buffer->record('org_a', 'storage.gb', 3);

    expect($buffer->cumulative('org_a', 'api.calls'))->toBe(15)
        ->and($buffer->cumulative('org_a', 'storage.gb'))->toBe(3)
        ->and($buffer->cumulative('org_a', 'unknown'))->toBe(0);
});

it('survives being re-instantiated — the total is on disk, not in the object', function (): void {
    databaseBuffer()->record('org_a', 'api.calls', 42);

    // A fresh buffer (as after a crash/restart) still sees the persisted total.
    expect(databaseBuffer()->cumulative('org_a', 'api.calls'))->toBe(42);
});

it('snapshots cumulative totals for the reporter, scoped by org', function (): void {
    $buffer = databaseBuffer();
    $buffer->record('org_a', 'api.calls', 7);
    $buffer->record('org_b', 'api.calls', 9);

    $all = $buffer->snapshot();
    $scoped = $buffer->snapshot('org_a');

    expect($all)->toHaveCount(2)
        ->and($scoped)->toHaveCount(1)
        ->and($scoped[0])->toBeInstanceOf(CumulativeUsage::class)
        ->and($scoped[0]->org)->toBe('org_a')
        ->and($scoped[0]->cumulative)->toBe(7)
        ->and($scoped[0]->seq)->toBe(1);
});

it('ignores a non-positive record', function (): void {
    $buffer = databaseBuffer();
    $buffer->record('org_a', 'api.calls', 0);
    $buffer->record('org_a', 'api.calls', -5);

    expect($buffer->cumulative('org_a', 'api.calls'))->toBe(0)
        ->and($buffer->snapshot())->toHaveCount(0);
});
