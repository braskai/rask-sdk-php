<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** HTTP 401: the key is missing, malformed, expired or revoked. */
class AuthenticationError extends RaskError
{
}
