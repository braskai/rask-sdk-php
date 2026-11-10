<?php

declare(strict_types=1);

namespace Rask\Sdk\Core;

/**
 * Base of every object the API returns.
 *
 * The decoded JSON is kept whole next to the typed properties, so a field, enum value or union variant this SDK version
 * has never heard of is still there: toArray(), get('/a/b') and $model['a'] read it. Typed properties are all nullable
 * and a value of an unexpected type reads as null there, never as an exception.
 *
 * Property names are the API's own (snake_case), the same names you use in request arrays.
 *
 * @phpstan-consistent-constructor
 */
abstract class Model implements UnionMember
{
    /** @var list<string> */
    protected const FIELDS = [];

    /** @var array<array-key, mixed> */
    private array $raw;

    private ?RawResponse $rawResponse;

    /** @param array<array-key, mixed> $data */
    public function __construct(array $data = [], ?RawResponse $response = null)
    {
        $this->raw = $data;
        $this->rawResponse = $response;
    }

    /**
     * The JSON as PHP arrays, unknown members included. An empty JSON object and an empty list both read as [] here;
     * response()->body has the exact text.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /** Reads any value by JSON Pointer, for example get('/details/stage'). "" and "/" are the whole object. */
    public function get(string $pointer, mixed $default = null): mixed
    {
        if ($pointer === '' || $pointer === '/') {
            return $this->raw;
        }
        $node = $this->raw;
        foreach (explode('/', ltrim($pointer, '/')) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $part);
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    /**
     * Top-level fields this SDK version does not declare.
     *
     * @return array<array-key, mixed>
     */
    public function additionalFields(): array
    {
        return array_diff_key($this->raw, array_flip(static::FIELDS));
    }

    /** Status and headers of the HTTP response this object came from; null on an object nested in another. */
    public function response(): ?RawResponse
    {
        return $this->rawResponse;
    }

    /** The Idempotency-Key the call sent, if it sent one. */
    public function idempotencyKey(): ?string
    {
        return $this->rawResponse?->idempotency_key;
    }

    /** True on the empty result of a call with if_none_match that the server answered 304: every property is null. */
    public function isNotModified(): bool
    {
        return $this->rawResponse?->status === 304;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->raw[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->raw[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('API objects are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('API objects are read-only.');
    }

    public function jsonSerialize(): mixed
    {
        return $this->raw === [] ? new \stdClass() : $this->raw;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['data' => $this->raw, 'status' => $this->rawResponse?->status];
    }

    /** @return array{data: array<array-key, mixed>} */
    public function __serialize(): array
    {
        return ['data' => $this->raw];
    }

    /** @param array{data: array<array-key, mixed>} $data */
    public function __unserialize(array $data): void
    {
        $this->__construct($data['data']);
    }
}
