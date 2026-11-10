<?php

declare(strict_types=1);

namespace Rask\Sdk\Internal;

use Rask\Sdk\Core\RawResponse;
use Rask\Sdk\Exception as E;
use Rask\Sdk\Model\Problem;

/** @internal */
final class ErrorFactory
{
    /** @var array<int, class-string<E\RaskError>> */
    private const BY_STATUS = [
        400 => E\BadRequestError::class,
        401 => E\AuthenticationError::class,
        402 => E\InsufficientBalanceError::class,
        403 => E\PermissionDeniedError::class,
        404 => E\NotFoundError::class,
        409 => E\ConflictError::class,
        410 => E\GoneError::class,
        412 => E\PreconditionFailedError::class,
        413 => E\ContentTooLargeError::class,
        415 => E\UnsupportedMediaTypeError::class,
        422 => E\ValidationError::class,
        428 => E\PreconditionRequiredError::class,
        429 => E\RateLimitError::class,
        500 => E\InternalServerError::class,
        502 => E\ServiceUnavailableError::class,
        503 => E\ServiceUnavailableError::class,
        504 => E\ServiceUnavailableError::class,
    ];

    /** The class comes from the status alone, so an HTML body or a code this SDK has never seen still raises typed. */
    public static function fromResponse(RawResponse $response): E\RaskError
    {
        $problem = self::problem($response->body);
        $class = self::BY_STATUS[$response->status] ?? E\RaskError::class;
        $text = $problem->detail ?? $problem->title ?? "The API answered HTTP {$response->status}.";
        $code = $problem?->code === null ? '' : " [{$problem->code}]";
        return new $class($text . $code, $response->status, $problem, $response, $response->idempotency_key);
    }

    private static function problem(string $body): ?Problem
    {
        $decoded = json_decode($body, true);
        return is_array($decoded) && !array_is_list($decoded) ? new Problem($decoded) : null;
    }
}
