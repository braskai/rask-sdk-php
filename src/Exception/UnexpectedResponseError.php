<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** A response this SDK cannot read as the API's: a success that is not JSON, a redirect loop. Check base_url. */
class UnexpectedResponseError extends RaskError
{
}
