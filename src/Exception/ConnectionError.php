<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** The request got no complete response: connection refused or lost, DNS failure. Retries ran out. */
class ConnectionError extends RaskError
{
}
