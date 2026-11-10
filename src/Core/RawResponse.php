<?php

declare(strict_types=1);

namespace Rask\Sdk\Core;

use Rask\Sdk\Exception\UnexpectedResponseError;

/** The HTTP response behind a result or an error: no second call needed to read a status or a header. */
final class RawResponse
{
    public readonly ?string $request_id;

    /**
     * @param array<string, string> $headers lowercase name => value
     * @param string $body the exact response text
     * @param string|null $idempotency_key the key the call sent, if any
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly ?string $idempotency_key = null,
    ) {
        $this->request_id = $headers['x-request-id'] ?? null;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function etag(): ?string
    {
        return $this->header('etag');
    }

    public function location(): ?string
    {
        return $this->header('location');
    }

    public function idempotentReplayed(): bool
    {
        return $this->header('idempotent-replayed') === 'true';
    }

    /** Seconds from Retry-After, as a number or an HTTP date. */
    public function retryAfter(): ?int
    {
        $value = $this->header('retry-after');
        if ($value === null) {
            return null;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        $time = strtotime($value);
        return $time === false ? null : max(0, $time - time());
    }

    /**
     * The body as PHP arrays.
     *
     * @return array<array-key, mixed>
     * @throws UnexpectedResponseError when a success body is not a JSON object, as behind a misconfigured gateway
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }
        try {
            $decoded = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new UnexpectedResponseError("HTTP {$this->status} answered with a body that is not JSON. Check base_url.", $this->status, null, $this, $this->idempotency_key);
        }
        if (!is_array($decoded)) {
            throw new UnexpectedResponseError("HTTP {$this->status} answered with JSON that is not an object. Check base_url.", $this->status, null, $this, $this->idempotency_key);
        }
        return $decoded;
    }
}
