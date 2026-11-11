<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

use Rask\Sdk\Core\RawResponse;
use Rask\Sdk\Model\Problem;

/**
 * The request got no complete response: connection refused or lost, DNS failure. Retries ran out, or the failure is one
 * that no retry mends: a certificate that fails verification is raised at once.
 */
class ConnectionError extends RaskError
{
    private readonly bool $mayPass;

    /** @param bool $mayPass false when the next attempt would fail the same way */
    public function __construct(
        string $message,
        int $status = 0,
        ?Problem $problem = null,
        ?RawResponse $response = null,
        ?string $idempotencyKey = null,
        ?\Throwable $previous = null,
        bool $mayPass = true,
    ) {
        parent::__construct($message, $status, $problem, $response, $idempotencyKey, $previous);
        $this->mayPass = $mayPass;
    }

    /**
     * Whether a later attempt may get through. A certificate that fails verification is the same on every attempt, so
     * a call does not retry it, and jobs->wait() does not take it for an outage.
     *
     * @internal
     */
    public function mayPass(): bool
    {
        return $this->mayPass;
    }
}
