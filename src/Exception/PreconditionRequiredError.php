<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** HTTP 428: the operation needs if_match. Never retried automatically. */
class PreconditionRequiredError extends RaskError
{
}
