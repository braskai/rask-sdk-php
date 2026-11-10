<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rask\Sdk\Rask;

/** A PSR-18 client that answers from a queue and keeps what it was asked. */
final class FakeHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<string> bodies as they were at send time, since a stream moves on */
    public array $bodies = [];

    /** @param list<ResponseInterface|\Throwable> $queue */
    public function __construct(public array $queue = [])
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $this->bodies[] = (string) $request->getBody();
        $next = array_shift($this->queue) ?? self::json(200, ['object' => 'empty']);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next;
    }

    /**
     * @param array<array-key, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    public static function connectionLost(string $message = 'cURL error 7: Failed to connect'): ConnectException
    {
        return new ConnectException($message, new Request('GET', 'http://unused.example/'));
    }

    /**
     * A client on this fake, whose sleeps pass at once and move its clock.
     *
     * @param array<string, mixed> $options
     */
    public function client(array $options = [], ?array &$sleeps = null): Rask
    {
        $sleeps = [];
        $now = 1000.0;
        return new Rask($options + [
            'api_key' => 'raskai_test_key_unit',
            'base_url' => 'https://gw.example.com/rask/v3',
            'http_client' => $this,
            'sleeper' => static function (int|float $seconds) use (&$sleeps, &$now): void {
                $sleeps[] = $seconds;
                $now += $seconds;
            },
            'clock' => static function () use (&$now): float {
                return $now;
            },
        ]);
    }
}
