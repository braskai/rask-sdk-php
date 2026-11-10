<?php

declare(strict_types=1);

namespace Rask\Sdk\Core;

use Rask\Sdk\Internal\Transport;

/** Base of the resource groups on the client: $rask->projects, $rask->jobs and so on. */
abstract class Resource
{
    /** @internal */
    public function __construct(protected readonly Transport $transport)
    {
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['resource' => static::class];
    }
}
