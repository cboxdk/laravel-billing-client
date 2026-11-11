<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Http;

/**
 * An id as Billing sends it, as the string the client hands on.
 *
 * Billing v2 sends public ids (`inv_…`, `sub_…`, `lir_…`, `apr_…`). Billing before v2 sent the
 * same fields as positive integers. The client reads both, so one release works against either:
 * an integer id becomes its decimal string (`17` → `"17"`), which is also what an older Billing
 * expects back in a path.
 */
class WireId
{
    /** The id, or null when the value is not one (missing, empty, zero, negative, another type). */
    public static function from(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }
}
