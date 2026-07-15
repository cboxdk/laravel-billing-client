<?php

declare(strict_types=1);

use Cbox\Billing\Client\Buffers\DatabaseUsageBuffer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable usage ledger backing {@see DatabaseUsageBuffer}.
 * One row per (org, meter) holding the monotonic cumulative total and seq; the unique
 * key makes the seed insert idempotent so concurrent workers cannot create duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_client_usage', function (Blueprint $table): void {
            $table->id();
            $table->string('org');
            $table->string('meter');
            $table->unsignedBigInteger('cumulative')->default(0);
            $table->unsignedBigInteger('seq')->default(0);
            $table->timestamp('updated_at')->nullable();

            $table->unique(['org', 'meter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_client_usage');
    }
};
