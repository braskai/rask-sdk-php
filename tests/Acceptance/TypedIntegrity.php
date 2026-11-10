<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Acceptance;

use Rask\Sdk\Core\Model;
use Rask\Sdk\Model\UnknownVariant;

/**
 * Walks a decoded model and checks its typed properties against the raw JSON next to them, so that the generated
 * hydration is tested on every response shape, not only the raw copy.
 */
final class TypedIntegrity
{
    /** @param list<string> $failures */
    public static function assertMatchesRaw(Model $model, string $where, array &$failures): void
    {
        if ($model instanceof UnknownVariant) {
            return;
        }
        $raw = $model->toArray();
        foreach ((new \ReflectionObject($model))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $typed = $property->getValue($model);
            $value = $raw[$name] ?? null;
            self::compare($typed, $value, "$where.$name", $failures);
        }
    }

    /** @param list<string> $failures */
    private static function compare(mixed $typed, mixed $raw, string $where, array &$failures): void
    {
        if ($typed instanceof Model) {
            if ($typed->toArray() !== $raw) {
                $failures[] = "$where: the nested model lost data";
            }
            self::assertMatchesRaw($typed, $where, $failures);
            return;
        }
        if (is_array($typed) && is_array($raw) && array_is_list($typed) && $typed !== [] && $typed[0] instanceof Model) {
            if (count($typed) !== count($raw)) {
                $failures[] = "$where: the list lost items";
            }
            foreach ($typed as $index => $item) {
                self::compare($item, $raw[$index] ?? null, "$where/$index", $failures);
            }
            return;
        }
        if (is_float($typed) && is_int($raw)) {
            $raw = (float) $raw;
        }
        if ($typed !== $raw) {
            $failures[] = "$where: typed " . json_encode($typed) . ', raw ' . json_encode($raw);
        }
    }
}
