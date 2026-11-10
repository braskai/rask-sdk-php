<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Fails the way Guzzle does: the exception holds the request it could not send. */
final class ThrowingHttpClient implements ClientInterface
{
    public function __construct(private readonly string $message)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new ConnectException($this->message, $request);
    }
}
