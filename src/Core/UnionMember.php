<?php

declare(strict_types=1);

namespace Rask\Sdk\Core;

/**
 * What every variant of a union can do, including a variant this SDK version does not know.
 *
 * @extends \ArrayAccess<string, mixed>
 */
interface UnionMember extends \ArrayAccess, \JsonSerializable
{
    /** @return array<array-key, mixed> */
    public function toArray(): array;

    public function get(string $pointer, mixed $default = null): mixed;

    /** @return array<array-key, mixed> */
    public function additionalFields(): array;
}
