<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rask\Sdk\Exception\JobCanceledError;
use Rask\Sdk\Exception\JobFailedError;
use Rask\Sdk\Exception\NotFoundError;
use Rask\Sdk\Exception\RaskError;
use Rask\Sdk\Exception\UnexpectedResponseError;
use Rask\Sdk\Exception\UsageError;
use Rask\Sdk\Exception\WaitTimeoutError;
use Rask\Sdk\Resource\Jobs;

final class WaitTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private static function job(string $status, array $extra = [], string $etag = '"j-1"'): \GuzzleHttp\Psr7\Response
    {
        return FakeHttpClient::json(200, ['id' => 'j', 'status' => $status] + $extra, ['ETag' => $etag, 'Retry-After' => '3']);
    }

    public function testDefaultTimeoutIsFourHours(): void
    {
        $this->assertSame(14400, Jobs::DEFAULT_WAIT_TIMEOUT_SECONDS);
    }

    public function testItLongPollsAndHonoursRetryAfter(): void
    {
        $http = new FakeHttpClient([self::job('queued'), self::job('running'), self::job('succeeded')]);
        $job = $http->client([], $sleeps)->jobs->wait('j');
        $this->assertSame('succeeded', $job->status);
        $this->assertSame([3.0, 3.0], $sleeps);
        $this->assertSame('wait_seconds=20', $http->requests[0]->getUri()->getQuery());
        $this->assertFalse($http->requests[0]->hasHeader('If-None-Match'));
        $this->assertSame('"j-1"', $http->requests[1]->getHeaderLine('If-None-Match'));
    }

    public function testNotModifiedKeepsWaiting(): void
    {
        $http = new FakeHttpClient([self::job('running'), new Response(304, ['Retry-After' => '2']), self::job('succeeded')]);
        $this->assertSame('succeeded', $http->client()->jobs->wait('j')->status);
    }

    public function testNoIfNoneMatchNoNotModifiedResult(): void
    {
        $http = new FakeHttpClient([new Response(304)]);
        $this->expectException(RaskError::class);
        $http->client()->jobs->getJob('j');
    }

    public function testACallWithIfNoneMatchCanReadNotModified(): void
    {
        $http = new FakeHttpClient([new Response(304)]);
        $job = $http->client()->jobs->getJob('j', [], ['if_none_match' => '"a"']);
        $this->assertTrue($job->isNotModified());
        $this->assertNull($job->id);
    }

    public function testFailedRaisesWithTheProblem(): void
    {
        $error = ['status' => 500, 'code' => 'dub_voiceover_failed', 'detail' => 'The voiceover failed.', 'retry' => 'new_key'];
        $http = new FakeHttpClient([self::job('failed', ['error' => $error])]);
        try {
            $http->client()->jobs->wait('j');
            $this->fail('Must raise.');
        } catch (JobFailedError $e) {
            $this->assertSame('dub_voiceover_failed', $e->code);
            $this->assertSame(500, $e->status);
            $this->assertSame('new_key', $e->retry);
            $this->assertSame('The voiceover failed.', $e->getMessage());
            $this->assertSame('j', $e->job->id);
        }
    }

    public function testCanceledRaises(): void
    {
        $http = new FakeHttpClient([self::job('canceled')]);
        $this->expectException(JobCanceledError::class);
        $http->client()->jobs->wait('j');
    }

    /** @return iterable<string, array{string, class-string<RaskError>}> */
    public static function finished(): iterable
    {
        yield 'failed' => ['failed', JobFailedError::class];
        yield 'canceled' => ['canceled', JobCanceledError::class];
    }

    /**
     * The contract has job problems with a status that a call retries, onscreen_provider_unavailable among them. A
     * finished job answers the same on every poll, so taking its problem for an outage would poll until the timeout.
     *
     * @param class-string<RaskError> $class
     */
    #[DataProvider('finished')]
    public function testAFinishedJobEndsTheWaitWhateverStatusItsProblemCarries(string $status, string $class): void
    {
        $error = ['status' => 503, 'code' => 'onscreen_provider_unavailable'];
        $http = new FakeHttpClient(array_fill(0, 30, self::job($status, ['error' => $error])));
        try {
            $http->client([], $sleeps)->jobs->wait('j', 60);
            $this->fail('Must raise.');
        } catch (RaskError $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertSame(503, $e->status);
            $this->assertSame('onscreen_provider_unavailable', $e->code);
        }
        $this->assertCount(1, $http->requests);
        $this->assertSame([], $sleeps);
    }

    public function testAnUnknownStatusIsStillRunning(): void
    {
        $http = new FakeHttpClient([self::job('s12_future_status'), self::job('succeeded')]);
        $this->assertSame('succeeded', $http->client()->jobs->wait('j')->status);
        $this->assertCount(2, $http->requests);
    }

    public function testTimeoutRaisesLeavesTheJobRunningAndKeepsTheLastRead(): void
    {
        $running = array_fill(0, 200, self::job('running'));
        $http = new FakeHttpClient($running);
        try {
            $http->client()->jobs->wait('j', 30);
            $this->fail('Must raise.');
        } catch (WaitTimeoutError $e) {
            $this->assertSame('j', $e->job_id);
            $this->assertSame('running', $e->last_job?->status);
        }
        foreach ($http->requests as $request) {
            $this->assertSame('GET', $request->getMethod());
        }
    }

    public function testTheLongPollNeverHoldsPastTheDeadline(): void
    {
        $http = new FakeHttpClient(array_fill(0, 20, self::job('running')));
        try {
            $http->client()->jobs->wait('j', 7);
        } catch (WaitTimeoutError) {
            // Expected.
        }
        $this->assertSame('wait_seconds=7', $http->requests[0]->getUri()->getQuery());
        $this->assertSame('wait_seconds=4', $http->requests[1]->getUri()->getQuery());
    }

    /** @return iterable<string, array{float}> */
    public static function timeoutsNoIntHolds(): iterable
    {
        yield 'for ever' => [INF];
        yield 'more seconds than an int holds' => [1e19];
    }

    /**
     * The time left was made an int before it was capped at 20 s. One beyond an int came out as 0, so the wait did not
     * long-poll at all, and PHP 8.5 warns of such a conversion on every poll.
     */
    #[DataProvider('timeoutsNoIntHolds')]
    public function testATimeoutNoIntHoldsStillLongPolls(float $timeout): void
    {
        $http = new FakeHttpClient([self::job('running'), self::job('succeeded')]);
        $this->assertSame('succeeded', $http->client()->jobs->wait('j', $timeout)->status);
        $this->assertSame('wait_seconds=20', $http->requests[0]->getUri()->getQuery());
        $this->assertSame('wait_seconds=20', $http->requests[1]->getUri()->getQuery());
    }

    /** Transport gives up after 120 s; an outage that long does not end a 4 h wait. */
    public function testAnOutageLongerThanTheRetryBudgetDoesNotEndTheWait(): void
    {
        $down = array_fill(0, 40, new Response(503, ['Retry-After' => '30']));
        $http = new FakeHttpClient([...$down, self::job('succeeded')]);
        $job = $http->client([], $sleeps)->jobs->wait('j');
        $this->assertSame('succeeded', $job->status);
        $this->assertGreaterThan(120, array_sum($sleeps));
    }

    /**
     * Nor does a connection that keeps failing for as long. Only a failure that no retry mends ends the wait, and
     * CertificateTest has that one.
     */
    public function testConnectionsThatFailForLongerThanTheRetryBudgetDoNotEndTheWait(): void
    {
        $http = new FakeHttpClient([...array_fill(0, 40, FakeHttpClient::connectionLost()), self::job('succeeded')]);
        $job = $http->client([], $sleeps)->jobs->wait('j');
        $this->assertSame('succeeded', $job->status);
        $this->assertGreaterThan(120, array_sum($sleeps));
        $this->assertCount(41, $http->requests);
    }

    public function testAnOutagePastTheDeadlineEndsAsATimeout(): void
    {
        $http = new FakeHttpClient(array_fill(0, 40, new Response(503, ['Retry-After' => '30'])));
        try {
            $http->client()->jobs->wait('j', 300);
            $this->fail('Must raise.');
        } catch (WaitTimeoutError $e) {
            $this->assertInstanceOf(RaskError::class, $e);
        }
    }

    /** The next poll would get the same redirect. Taken for an outage, it would come back hours later as a timeout. */
    public function testARedirectThatCannotBeFollowedEndsTheWait(): void
    {
        // Every poll is redirected to a Location without a host. Guzzle refuses to send to one, with the exception it
        // has for a failure of the network, and so does this client.
        $http = new class () implements ClientInterface {
            public int $requests = 0;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->requests;
                if ($request->getUri()->getHost() === '') {
                    throw new RequestException('URI must include a scheme and host.', $request);
                }
                return new Response(307, ['Location' => 'http:/x']);
            }
        };
        try {
            (new FakeHttpClient())->client(['http_client' => $http], $sleeps)->jobs->wait('j');
            $this->fail('Must raise.');
        } catch (RaskError $e) {
            $this->assertInstanceOf(UnexpectedResponseError::class, $e);
            $this->assertSame(307, $e->status);
        }
        $this->assertSame(1, $http->requests);
        $this->assertSame([], $sleeps);
    }

    public function testNotFoundEndsTheWait(): void
    {
        $http = new FakeHttpClient([new Response(404)]);
        $this->expectException(NotFoundError::class);
        $http->client()->jobs->wait('j');
    }

    public function testATimeoutMustBePositive(): void
    {
        $this->expectException(UsageError::class);
        (new FakeHttpClient())->client()->jobs->wait('j', 0);
    }
}
