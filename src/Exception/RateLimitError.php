<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** HTTP 429 after the retries ran out: retry_after says how long to wait. */
class RateLimitError extends RaskError
{
}
