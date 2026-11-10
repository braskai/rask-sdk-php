<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rask\Sdk\Exception\ConnectionError;
use Rask\Sdk\Exception\RaskError;
use Rask\Sdk\Exception\RateLimitError;
use Rask\Sdk\Exception\TimeoutError;
use Rask\Sdk\Internal\Transport;

final class RetryTest extends TestCase
{
    public function testBackoffGrowsByOneAndAHalfWithJitterAndStopsAtThirty(): void
    {
        foreach ([1 => 0.5, 2 => 0.75, 3 => 1.125, 4 => 1.6875] as $attempt => $base) {
            for ($i = 0; $i < 200; ++$i) {
                $wait = Transport::backoff($attempt);
                $this->assertGreaterThanOrEqual($base * 0.75, $wait);
                $this->assertLessThanOrEqual($base * 1.25, $wait);
            }
        }
        $this->assertLessThanOrEqual(30 * 1.25, Transport::backoff(40));
        $this->assertGreaterThanOrEqual(30 * 0.75, Transport::backoff(40));
    }

    /** @return iterable<string, array{int}> */
    public static function retried(): iterable
    {
        yield '429' => [429];
        yield '502' => [502];
        yield '503' => [503];
        yield '504' => [504];
    }

    #[DataProvider('retried')]
    public function testStatusIsRetried(int $status): void
    {
        $http = new FakeHttpClient([new Response($status), FakeHttpClient::json(200, ['id' => 'me'])]);
        $this->assertSame('me', $http->client()->account->getMe()['id']);
        $this->assertCount(2, $http->requests);
    }

    /** @return iterable<string, array{int}> */
    public static function notRetried(): iterable
    {
        foreach ([400, 401, 402, 403, 404, 409, 410, 412, 413, 415, 422, 428, 500] as $status) {
            yield (string) $status => [$status];
        }
    }

    #[DataProvider('notRetried')]
    public function testStatusIsNotRetried(int $status): void
    {
        $http = new FakeHttpClient([new Response($status), FakeHttpClient::json(200, [])]);
        try {
            $http->client()->account->getMe();
            $this->fail('Must raise.');
        } catch (RaskError $e) {
            $this->assertSame($status, $e->status);
            $this->assertCount(1, $http->requests);
        }
    }

    public function testRetryAfterSecondsIsAFloorForTheWait(): void
    {
        $http = new FakeHttpClient([new Response(429, ['Retry-After' => '7']), FakeHttpClient::json(200, [])]);
        $http->client([], $sleeps)->account->getMe();
        $this->assertCount(1, $sleeps);
        $this->assertGreaterThanOrEqual(7, $sleeps[0]);
    }

    public function testRetryAfterAsADateIsHonoured(): void
    {
        $when = gmdate('D, d M Y H:i:s \G\M\T', time() + 30);
        $http = new FakeHttpClient([new Response(503, ['Retry-After' => $when]), FakeHttpClient::json(200, [])]);
        $http->client([], $sleeps)->account->getMe();
        $this->assertGreaterThanOrEqual(28, $sleeps[0]);
        $this->assertLessThanOrEqual(31, $sleeps[0]);
    }

    public function testARetryAfterPastTheBudgetEndsTheRetries(): void
    {
        $http = new FakeHttpClient([new Response(429, ['Retry-After' => '130']), FakeHttpClient::json(200, [])]);
        try {
            $http->client([], $sleeps)->account->getMe();
            $this->fail('Must raise.');
        } catch (RateLimitError $e) {
            $this->assertSame(130, $e->retry_after);
            $this->assertSame([], $sleeps);
        }
    }

    public function testAConnectionErrorIsRetriedAndTheKeyStays(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::connectionLost(), FakeHttpClient::json(201, ['id' => 'f'])]);
        $folder = $http->client()->projects->createFolder(['name' => 'x']);
        $this->assertSame('f', $folder->id);
        $this->assertSame(
            $http->requests[0]->getHeaderLine('Idempotency-Key'),
            $http->requests[1]->getHeaderLine('Idempotency-Key'),
        );
        $this->assertSame($http->requests[0]->getHeaderLine('Idempotency-Key'), $folder->idempotencyKey());
    }

    public function testErrorsCarryTheKeyWhenTheNetworkFails(): void
    {
        $http = new FakeHttpClient(array_fill(0, 100, FakeHttpClient::connectionLost()));
        try {
            $http->client()->projects->createFolder(['name' => 'x'], ['idempotency_key' => 'mine']);
            $this->fail('Must raise.');
        } catch (ConnectionError $e) {
            $this->assertSame('mine', $e->idempotency_key);
        }
    }

    /** The 120 s budget, with the clock compressed: S3.total-budget runs for real under RUN_SLOW=1. */
    public function testRetriesStopWithinTheBudgetAndNoWaitPassesThirtySeconds(): void
    {
        $http = new FakeHttpClient(array_fill(0, 100, new Response(503)));
        try {
            $http->client([], $sleeps)->account->getMe();
            $this->fail('Must raise.');
        } catch (RaskError $e) {
            $this->assertSame(503, $e->status);
        }
        $this->assertLessThanOrEqual(120.0, array_sum($sleeps));
        $this->assertLessThanOrEqual(37.5, max($sleeps));
        $this->assertGreaterThanOrEqual(5, count($sleeps));
        $this->assertLessThan(100, count($http->requests));
    }

    public function testKeyInUseIsRetriedThreeTimes(): void
    {
        $inUse = FakeHttpClient::json(409, ['code' => 'idempotency_key_in_use', 'status' => 409], ['Retry-After' => '1']);
        $http = new FakeHttpClient([$inUse, $inUse, $inUse, $inUse, FakeHttpClient::json(201, [])]);
        try {
            $http->client()->projects->createFolder(['name' => 'x']);
            $this->fail('Must raise.');
        } catch (RaskError $e) {
            $this->assertSame('idempotency_key_in_use', $e->code);
            $this->assertCount(4, $http->requests);
        }
    }

    public function testATimeoutIsATimeoutError(): void
    {
        $this->assertTrue(is_a(TimeoutError::class, ConnectionError::class, true));
        $http = new FakeHttpClient(array_fill(0, 2, FakeHttpClient::connectionLost('cURL error 28: Operation timed out')));
        $http->queue[] = FakeHttpClient::json(200, ['id' => 'me']);
        $this->assertSame('me', $http->client()->account->getMe()['id']);
        $this->assertCount(3, $http->requests);
    }
}
