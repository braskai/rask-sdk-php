<?php

declare(strict_types=1);

namespace Rask\Sdk\Internal;

use Rask\Sdk\Core\Model;
use Rask\Sdk\Exception\UsageError;

/**
 * Request JSON from PHP arrays. The traps it closes:
 *
 * - An empty PHP array is `[]` to json_encode. Where the contract has an object it must be `{}`.
 * - A list with a gap, as array_filter leaves it, encodes as an object. Where the contract has a list it must be a list.
 * - A null member is never sent (S12): the API clears a field with "", and a null in a request is an error.
 *
 * @internal
 */
final class Encoder
{
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** @param array<array-key, mixed> $body */
    public static function body(array $body, string $schema): string
    {
        try {
            return json_encode(self::value($body, ['r', $schema]), self::FLAGS);
        } catch (\JsonException $e) {
            throw new UsageError('The request body cannot be encoded as JSON: ' . $e->getMessage());
        }
    }

    /** @param array<array-key, mixed> $query */
    public static function query(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            if ($value === null) {
                continue;
            }
            // style=form, explode=false: one parameter, comma-separated, with commas inside a value encoded.
            $text = is_array($value)
                ? implode(',', array_map(static fn (mixed $item): string => rawurlencode(self::scalar($item)), $value))
                : rawurlencode(self::scalar($value));
            $pairs[] = rawurlencode((string) $name) . '=' . $text;
        }
        return $pairs === [] ? '' : '?' . implode('&', $pairs);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339),
            default => throw new UsageError('A query value must be a scalar, a date or a list of those; got ' . get_debug_type($value) . '.'),
        };
    }

    /** @param array<int, mixed>|null $node */
    private static function value(mixed $value, ?array $node): mixed
    {
        if ($value instanceof Model) {
            $value = $value->toArray();
        } elseif ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::RFC3339);
        }
        if (!is_array($value)) {
            return $value;
        }
        $node = self::resolve($value, $node);
        $kind = $node[0] ?? null;
        $inner = $node[1] ?? null;
        switch ($kind) {
            case 'o':
                /** @var array<array-key, array<int, mixed>> $inner */
                return self::members($value, static fn (string|int $name): ?array => $inner[$name] ?? null);
            case 'm':
                /** @var array<int, mixed>|null $inner */
                return self::members($value, static fn (string|int $name): ?array => $inner);
            case 'a':
                /** @var array<int, mixed>|null $inner */
                return array_map(static fn (mixed $item): mixed => self::value($item, $inner), array_values($value));
        }
        // Not described by the contract (an unknown union variant, say): the array's own form decides.
        if ($value === []) {
            return [];
        }
        return array_is_list($value)
            ? array_map(static fn (mixed $item): mixed => self::value($item, null), $value)
            : self::members($value, static fn (string|int $name): ?array => null);
    }

    /**
     * Follows references and picks a union's variant by its discriminator.
     *
     * @param array<array-key, mixed> $value
     * @param array<int, mixed>|null $node
     * @return array<int, mixed>|null
     */
    private static function resolve(array $value, ?array $node): ?array
    {
        while ($node !== null && ($node[0] === 'r' || $node[0] === 'u')) {
            if ($node[0] === 'r') {
                /** @var string $name */
                $name = $node[1];
                $node = RequestShapes::SCHEMAS[$name] ?? null;
                continue;
            }
            /** @var string $discriminator */
            $discriminator = $node[1];
            /** @var array<string, string> $variants */
            $variants = $node[2];
            $tag = $value[$discriminator] ?? null;
            $node = is_string($tag) && isset($variants[$tag]) ? ['r', $variants[$tag]] : null;
        }
        return $node;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param \Closure(string|int): (array<int, mixed>|null) $child
     */
    private static function members(array $value, \Closure $child): \stdClass
    {
        $object = new \stdClass();
        foreach ($value as $name => $item) {
            if ($item !== null) {
                $object->{(string) $name} = self::value($item, $child($name));
            }
        }
        return $object;
    }
}
