<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** HTTP 403: see required_scopes and granted_scopes. */
class PermissionDeniedError extends RaskError
{
}
