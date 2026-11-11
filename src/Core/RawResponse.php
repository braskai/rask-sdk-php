<?php

declare(strict_types=1);

namespace Rask\Sdk\Core;

use Rask\Sdk\Exception\UnexpectedResponseError;

/** The HTTP response behind a result or an error: no second call needed to read a status or a header. */
final class RawResponse
{
    /**
     * 2^31 s, what HTTP takes for a number of seconds too large to hold (RFC 9111, 1.2.2): about 68 years, so a time
     * or another wait can still be added to it. Where an int has 32 bits it does not hold 2^31, and the cap is the
     * largest int.
     */
    private const MAX_RETRY_AFTER = PHP_INT_SIZE > 4 ? 2147483648 : PHP_INT_MAX;

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

    /**
     * Seconds from Retry-After, as a number or an HTTP date: at most 2^31, and null when it is absent, unreadable or
     * negative.
     */
    public function retryAfter(): ?int
    {
        $value = $this->header('retry-after');
        if ($value === null) {
            return null;
        }
        if (is_numeric($value)) {
            // A number never reaches strtotime(), which reads "-5" as a time zone and answers five hours from now.
            $seconds = (float) $value;
            // Capped while it is a float: one beyond an int does not convert to it.
            return $seconds < 0 ? null : (int) min(ceil($seconds), self::MAX_RETRY_AFTER);
        }
        $time = strtotime($value);
        return $time === false ? null : min(max(0, $time - time()), self::MAX_RETRY_AFTER);
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
