<?php

declare(strict_types=1);

namespace Rask\Sdk\Internal;

/**
 * Holds the API key so that no dump shows it. var_dump and print_r go through __debugInfo; var_export, (array) and
 * get_object_vars see only a Closure; serialize refuses.
 *
 * @internal
 */
final class Secret implements \Stringable, \JsonSerializable
{
    /** @var \Closure(): string */
    private readonly \Closure $reveal;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        $this->reveal = static fn (): string => $value;
    }

    public function reveal(): string
    {
        return ($this->reveal)();
    }

    public function __toString(): string
    {
        return '***';
    }

    public function jsonSerialize(): string
    {
        return '***';
    }

    /** @return array{value: string} */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('The API key cannot be serialized.');
    }
}
