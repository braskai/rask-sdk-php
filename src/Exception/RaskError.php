<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

use Rask\Sdk\Core\RawResponse;
use Rask\Sdk\Model\Problem;
use Rask\Sdk\Model\ProblemFieldError;

/**
 * Base of everything the API or the network can raise.
 *
 * The members of the API's Problem are public properties under the API's names. Any other member of the Problem, also
 * one this SDK version does not know, reads as a property too: $e->balance_minutes, $e->current_etag, $e->required_scopes.
 * The typed Problem is $e->problem.
 *
 * $e->code is the API's stable code, a string: branch on it, never on the message. getCode(), which PHP declares and
 * rethrowing code passes to `new Exception($message, $code)`, stays an int: the HTTP status.
 *
 * @property-read string|null $code The problem code, for example insufficient_balance. Unknown codes arrive unchanged.
 */
class RaskError extends ProblemMembers
{
    /** HTTP status; 0 when no response arrived. */
    public readonly int $status;

    public readonly ?string $type;

    public readonly ?string $title;

    public readonly ?string $detail;

    public readonly ?string $instance;

    public readonly ?string $request_id;

    public readonly ?bool $retryable;

    /** @see \Rask\Sdk\Enum\ProblemRetry */
    public readonly ?string $retry;

    /** @see \Rask\Sdk\Enum\ProblemRecommendation */
    public readonly ?string $recommendation;

    public readonly ?string $hint;

    /** @var list<ProblemFieldError> */
    public readonly array $errors;

    /** Seconds from the Retry-After header. */
    public readonly ?int $retry_after;

    /** The Idempotency-Key the call sent, if any. */
    public readonly ?string $idempotency_key;

    public readonly ?Problem $problem;

    private readonly ?RawResponse $rawResponse;

    public function __construct(
        string $message,
        int $status = 0,
        ?Problem $problem = null,
        ?RawResponse $response = null,
        ?string $idempotencyKey = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
        $this->status = $status;
        $this->problem = $problem;
        $this->rawResponse = $response;
        $this->type = $problem?->type;
        $this->title = $problem?->title;
        $this->detail = $problem?->detail;
        $this->instance = $problem?->instance;
        $this->retryable = $problem?->retryable;
        $this->retry = $problem?->retry;
        $this->recommendation = $problem?->recommendation;
        $this->hint = $problem?->hint;
        $this->errors = $problem->errors ?? [];
        $this->request_id = $response->request_id ?? self::requestIdFromInstance($problem?->instance);
        $this->retry_after = $response?->retryAfter();
        $this->idempotency_key = $idempotencyKey ?? $response?->idempotency_key;
    }

    /** Status and headers of the response, also when the body was not a Problem. */
    public function response(): ?RawResponse
    {
        return $this->rawResponse;
    }

    /** Headers of the response, lowercase. Empty when no response arrived. */
    public function header(string $name): ?string
    {
        return $this->rawResponse?->header($name);
    }

    /**
     * A Problem member by name: typed if this SDK version knows it, as sent if not. PHP calls this for $e->code too,
     * from outside the class, because Exception::$code is protected.
     */
    public function __get(string $name): mixed
    {
        if ($this->problem !== null && property_exists($this->problem, $name)) {
            return $this->problem->{$name};
        }
        return $this->problem?->offsetGet($name);
    }

    public function __isset(string $name): bool
    {
        return $this->problem !== null && $this->problem->offsetExists($name);
    }

    private static function requestIdFromInstance(?string $instance): ?string
    {
        return $instance !== null && str_starts_with($instance, 'urn:uuid:') ? substr($instance, 9) : null;
    }
}
