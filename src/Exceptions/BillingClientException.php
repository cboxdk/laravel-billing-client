<?php

declare(strict_types=1);

namespace Cbox\Billing\Client\Exceptions;

use RuntimeException;

/** Base for every error raised by the billing client SDK. */
class BillingClientException extends RuntimeException {}
