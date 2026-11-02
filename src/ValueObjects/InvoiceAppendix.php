<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\ValueObjects;

/**
 * A specification table printed after the lines of a manual invoice: plain text only, billing
 * never interprets a cell. 1 to 8 columns, up to 2 000 rows, every row (and the optional
 * `footer`) with exactly one cell per column.
 */
readonly class InvoiceAppendix
{
    /**
     * @param  list<array{key: string, label: string, align?: string}>  $columns
     * @param  list<list<string>>  $rows
     * @param  list<string>|null  $footer
     */
    public function __construct(
        public string $title,
        public array $columns,
        public array $rows,
        public ?array $footer = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'columns' => $this->columns,
            'rows' => $this->rows,
            'footer' => $this->footer,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
