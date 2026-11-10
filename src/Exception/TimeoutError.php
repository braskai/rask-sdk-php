<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** A request ran past timeout_seconds. Retries ran out. */
class TimeoutError extends ConnectionError
{
}
