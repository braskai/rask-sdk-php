<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** HTTP 412: the if_match is stale. current_etag has the current one. Never retried automatically. */
class PreconditionFailedError extends RaskError
{
}
