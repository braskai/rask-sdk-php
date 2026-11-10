<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** HTTP 409. State conflicts are not retried; idempotency_key_in_use is, up to 3 times, before this is raised. */
class ConflictError extends RaskError
{
}
