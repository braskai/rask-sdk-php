<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** HTTP 502, 503 or 504 after the retries ran out. */
class ServiceUnavailableError extends RaskError
{
}
