<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** The SDK was constructed or called wrongly. Nothing was sent. */
final class UsageError extends \InvalidArgumentException
{
}
