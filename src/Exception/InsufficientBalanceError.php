<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** HTTP 402: the balance does not cover the request. See balance_minutes, required_minutes, credit_type. */
class InsufficientBalanceError extends RaskError
{
}
