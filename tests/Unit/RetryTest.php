<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rask\Sdk\Core\RawResponse;
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

    /** @return iterable<string, array{string, int|null}> */
    public static function retryAfterForms(): iterable
    {
        yield 'seconds' => ['7', 7];
        yield 'a fraction, rounded up' => ['5.5', 6];
        yield 'a date in the past' => ['Wed, 21 Oct 2015 07:28:00 GMT', 0];
        yield 'the largest number under the cap' => ['2147483647', 2147483647];
        yield 'the cap' => ['2147483648', 2147483648];
        yield 'a number over the cap' => ['2147483649', 2147483648];
        yield 'more digits than an int holds' => ['99999999999999999999', 2147483648];
        yield 'an exponent' => ['1e20', 2147483648];
        yield 'a number no float holds' => ['1e400', 2147483648];
        yield 'a date in the year 9999' => ['Fri, 31 Dec 9999 23:59:59 GMT', 2147483648];
        yield 'a negative number' => ['-5', null];
        yield 'not a number' => ['NaN', null];
        yield 'infinity' => ['INF', null];
    }

    /**
     * No wait is longer than 2^31 s, which is what HTTP takes for a number of seconds too large to hold (RFC 9111,
     * 1.2.2), and a number is never read as a date: strtotime() takes "-5" for a time zone, five hours away.
     */
    #[DataProvider('retryAfterForms')]
    public function testRetryAfterIsSecondsWithinTheCap(string $header, ?int $seconds): void
    {
        $this->assertSame($seconds, (new RawResponse(503, ['retry-after' => $header], ''))->retryAfter());
    }

    public function testANegativeRetryAfterIsNoWaitAtAll(): void
    {
        $http = new FakeHttpClient([new Response(503, ['Retry-After' => '-5']), FakeHttpClient::json(200, ['id' => 'me'])]);
        $this->assertSame('me', $http->client([], $sleeps)->account->getMe()['id']);
        $this->assertCount(1, $sleeps);
        $this->assertLessThan(1, $sleeps[0]);
    }

    public function testARetryAfterNoWaitCanHoldEndsTheRetriesWithTheCapOnTheError(): void
    {
        $http = new FakeHttpClient([new Response(429, ['Retry-After' => '99999999999999999999']), FakeHttpClient::json(200, [])]);
        try {
            $http->client([], $sleeps)->account->getMe();
            $this->fail('Must raise.');
        } catch (RateLimitError $e) {
            $this->assertSame(2147483648, $e->retry_after);
            $this->assertSame([], $sleeps);
        }
    }

    /**
     * usleep() takes its microseconds as 32 bits, so a wait past 71 minutes wrapped round: this one, 1 ms over 2^32
     * microseconds, lasted 1 ms. jobs->wait() sleeps as long as a poll's Retry-After says, within its timeout of hours.
     */
    public function testTheDefaultSleeperDoesNotWrapAWaitOfOverAnHour(): void
    {
        $code = <<<'PHP'
            require $argv[1];
            $rask = new Rask\Sdk\Rask(['api_key' => 'k']);
            $transport = (new ReflectionProperty($rask->jobs, 'transport'))->getValue($rask->jobs);
            echo "asleep\n";
            $transport->pause(4294.968296);
            echo "awake\n";
            PHP;
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        $process = proc_open([PHP_BINARY, '-r', $code, '--', $autoload], [1 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        try {
            $this->assertSame("asleep\n", fgets($pipes[1]));
            $read = [$pipes[1]];
            $write = $except = null;
            // Half a second is 500 times what the wrapped wait took. Anything to read means it woke up or died.
            $this->assertSame(0, stream_select($read, $write, $except, 0, 500_000), 'The wait ended at once.');
        } finally {
            proc_terminate($process);
            fclose($pipes[1]);
            proc_close($process);
        }
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

    /** @return iterable<string, array{int, string}> */
    public static function certificateFailures(): iterable
    {
        yield 'whatever is wrong with it, on libcurl from 7.62' => [60, 'SSL certificate problem: self-signed certificate'];
        yield 'another host name, on libcurl before 7.62' => [51, "SSL: no alternative certificate subject name matches target host name '127.0.0.1'"];
    }

    /**
     * The certificate is the same on every attempt. CertificateTest gets 60 from a real endpoint; 51 is what Guzzle
     * reports on libcurl 7.52 for a certificate for another host name, and the PHP that runs these tests has a later
     * libcurl, so here both are made by hand.
     */
    #[DataProvider('certificateFailures')]
    public function testACertificateThatFailsVerificationIsNotRetried(int $errno, string $error): void
    {
        $http = new FakeHttpClient([FakeHttpClient::curlError($errno, $error), FakeHttpClient::json(201, ['id' => 'f'])]);
        try {
            $http->client([], $sleeps)->projects->createFolder(['name' => 'x'], ['idempotency_key' => 'mine']);
            $this->fail('Must raise.');
        } catch (ConnectionError $e) {
            $this->assertSame(ConnectionError::class, $e::class);
            $this->assertStringContainsString("cURL error $errno: $error", $e->getMessage());
            $this->assertSame('mine', $e->idempotency_key);
        }
        $this->assertCount(1, $http->requests);
        $this->assertSame([], $sleeps);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function failuresThatMayPass(): iterable
    {
        yield 'no connection' => [FakeHttpClient::curlError(7, 'Failed to connect to gw.example.com port 443')];
        yield 'a TLS handshake that broke' => [FakeHttpClient::curlError(35, 'Recv failure: Connection reset by peer')];
        yield 'a connection reset in mid-answer' => [FakeHttpClient::curlError(56, 'Recv failure: Connection reset by peer')];
        yield 'a code this SDK does not know' => [FakeHttpClient::curlError(999, 'Something new')];
        yield 'no code, as from Guzzle without cURL' => [FakeHttpClient::connectionLost('Connection refused for URI https://gw.example.com/rask/v3/me')];
        yield 'no code, as from another client, whatever its words' => [new \RuntimeException('SSL certificate problem: self-signed certificate')];
    }

    /** Only cURL's code makes a failure final. Whatever the SDK does not recognise by it may pass, and is retried. */
    #[DataProvider('failuresThatMayPass')]
    public function testAnyOtherConnectionFailureIsStillRetried(\Throwable $failure): void
    {
        $http = new FakeHttpClient([$failure, FakeHttpClient::json(200, ['id' => 'me'])]);
        $this->assertSame('me', $http->client([], $sleeps)->account->getMe()['id']);
        $this->assertCount(2, $http->requests);
        $this->assertCount(1, $sleeps);
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
