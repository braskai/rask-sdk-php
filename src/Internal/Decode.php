<?php

declare(strict_types=1);

namespace Rask\Sdk\Internal;

use Rask\Sdk\Core\Model;
use Rask\Sdk\Model\UnknownVariant;

/**
 * Reads typed properties from decoded JSON. Nothing here throws: a value of an unexpected type becomes null in the typed
 * property, and stays readable in the model's raw data.
 *
 * @internal
 */
final class Decode
{
    public static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public static function int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        return is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : null;
    }

    public static function float(mixed $value): ?float
    {
        return is_float($value) || is_int($value) ? (float) $value : null;
    }

    public static function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return T|null
     */
    public static function model(mixed $value, string $class): ?Model
    {
        return is_array($value) ? new $class($value) : null;
    }

    /**
     * @template T of object
     * @param class-string<T> $union
     * @return T|null
     */
    public static function union(mixed $value, string $union): ?object
    {
        if (!is_array($value)) {
            return null;
        }
        /** @var T $decoded */
        $decoded = self::variant($value, $union);
        return $decoded;
    }

    /** @param array<array-key, mixed> $value */
    private static function variant(array $value, string $union): mixed
    {
        [$discriminator, $variants] = Unions::TABLE[$union] ?? throw new \LogicException("Unknown union $union");
        $tag = $value[$discriminator] ?? null;
        $class = is_string($tag) ? ($variants[$tag] ?? UnknownVariant::class) : UnknownVariant::class;
        return new $class($value);
    }

    /** @return list<string>|null */
    public static function strings(mixed $value): ?array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : null;
    }

    /** @return list<int>|null */
    public static function ints(mixed $value): ?array
    {
        return is_array($value) ? array_values(array_filter($value, is_int(...))) : null;
    }

    /** @return list<float>|null */
    public static function floats(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $numbers = array_filter($value, static fn (mixed $item): bool => is_int($item) || is_float($item));
        return array_values(array_map(static fn (int|float $item): float => (float) $item, $numbers));
    }

    /**
     * @template T of Model
     * @param class-string<T> $class
     * @return list<T>|null
     */
    public static function models(mixed $value, string $class): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        return array_values(array_map(
            static fn (array $item): Model => new $class($item),
            array_filter($value, is_array(...)),
        ));
    }

    /**
     * @template T of object
     * @param class-string<T> $union
     * @return list<T>|null
     */
    public static function unions(mixed $value, string $union): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $items = [];
        foreach ($value as $item) {
            $decoded = self::union($item, $union);
            if ($decoded !== null) {
                $items[] = $decoded;
            }
        }
        return $items;
    }

    /** @return array<string, string>|null */
    public static function stringMap(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($item)) {
                $map[(string) $key] = $item;
            }
        }
        return $map;
    }

    /** @return array<string, mixed>|null */
    public static function map(mixed $value): ?array
    {
        /** @var array<string, mixed>|null */
        return is_array($value) ? $value : null;
    }

    /** @return list<string|null>|null */
    public static function nullableStrings(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        return array_values(array_filter($value, static fn (mixed $item): bool => $item === null || is_string($item)));
    }
}
