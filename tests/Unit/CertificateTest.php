<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rask\Sdk\Exception\ConnectionError;
use Rask\Sdk\Exception\RaskError;
use Rask\Sdk\Exception\UploadError;
use Rask\Sdk\Rask;

/**
 * A certificate that fails verification is the same on every attempt, so a retry only reports it later, and a wait that
 * took it for an outage would end hours later as a timeout, without its cause.
 *
 * Nothing here is simulated: Guzzle and cURL meet a real TLS endpoint on 127.0.0.1, with a certificate made for the test.
 */
final class CertificateTest extends TestCase
{
    private static string $certificates;

    /** @var list<TlsEndpoint> */
    private array $endpoints = [];

    /** @var list<string> */
    private array $paths = [];

    public static function setUpBeforeClass(): void
    {
        self::$certificates = TlsEndpoint::makeCertificates();
    }

    public static function tearDownAfterClass(): void
    {
        TlsEndpoint::removeCertificates(self::$certificates);
    }

    protected function tearDown(): void
    {
        foreach ($this->endpoints as $endpoint) {
            $endpoint->close();
        }
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    /** @param array<array-key, mixed> $body */
    private function serve(string $certificate, int $status = 200, array $body = ['id' => 'me']): TlsEndpoint
    {
        return $this->endpoints[] = new TlsEndpoint(self::$certificates, $certificate, $status, $body);
    }

    /**
     * A client for the endpoint whose waits pass at once and move its clock, so a retry costs a connection and no time.
     * Without an http_client it is on the Guzzle that the SDK makes for itself.
     *
     * @param array<string, mixed> $options
     */
    private function rask(TlsEndpoint $endpoint, array $options = [], ?array &$sleeps = null): Rask
    {
        $sleeps = [];
        $now = 1000.0;
        return new Rask($options + [
            'api_key' => 'raskai_test_key_unit',
            'base_url' => $endpoint->url() . '/v3',
            'sleeper' => static function (int|float $seconds) use (&$sleeps, &$now): void {
                $sleeps[] = $seconds;
                $now += $seconds;
            },
            'clock' => static function () use (&$now): float {
                return $now;
            },
        ]);
    }

    /**
     * Guzzle on cURL that trusts the CA of the test's certificates and nothing else. Under the trust of the system
     * every one of them has an unknown issuer, and cURL names that before it looks at the dates or at the name.
     */
    private static function trustingTheCa(): GuzzleClient
    {
        return new GuzzleClient(['verify' => self::$certificates . '/ca.crt', 'timeout' => 10, 'connect_timeout' => 5]);
    }

    /** The control: with a certificate that verifies the same endpoint answers, so the others fail on theirs alone. */
    public function testACertificateThatVerifiesIsServed(): void
    {
        $endpoint = $this->serve(TlsEndpoint::GOOD);
        $rask = $this->rask($endpoint, ['http_client' => self::trustingTheCa()], $sleeps);
        $this->assertSame('me', $rask->account->getMe()['id']);
        $this->assertSame([], $sleeps);
        $this->assertSame(1, $endpoint->connections());
    }

    /** @return iterable<string, array{string, bool, string}> the certificate, whether its CA is trusted, cURL's words */
    public static function failing(): iterable
    {
        yield 'self-signed' => [TlsEndpoint::SELF_SIGNED, false, '/self[- ]signed certificate/'];
        yield 'of an unknown issuer' => [TlsEndpoint::GOOD, false, '/unable to get local issuer certificate/'];
        yield 'expired' => [TlsEndpoint::EXPIRED, true, '/certificate has expired/'];
        yield 'for another host name' => [TlsEndpoint::WRONG_HOST, true, '/no alternative certificate subject name matches/'];
    }

    /** One attempt, and the error says what cURL said. All four are cURL's error 60. */
    #[DataProvider('failing')]
    public function testACallFailsAtOnce(string $certificate, bool $trustTheCa, string $cause): void
    {
        $endpoint = $this->serve($certificate);
        $rask = $this->rask($endpoint, $trustTheCa ? ['http_client' => self::trustingTheCa()] : [], $sleeps);
        try {
            $rask->account->getMe();
            $this->fail('Must raise.');
        } catch (ConnectionError $e) {
            $this->assertSame(ConnectionError::class, $e::class);
            $this->assertStringContainsString('cURL error 60:', $e->getMessage());
            $this->assertMatchesRegularExpression($cause, $e->getMessage());
            $this->assertNull($e->getPrevious(), 'A chained client exception would hold the request and its Authorization.');
        }
        $this->assertSame([], $sleeps);
        $this->assertSame(1, $endpoint->connections());
    }

    public function testAWaitEndsAtOnce(): void
    {
        $endpoint = $this->serve(TlsEndpoint::SELF_SIGNED);
        try {
            $this->rask($endpoint, [], $sleeps)->jobs->wait('j', 600);
            $this->fail('Must raise.');
        } catch (RaskError $e) {
            $this->assertSame(ConnectionError::class, $e::class, $e->getMessage());
            $this->assertStringContainsString('cURL error 60:', $e->getMessage());
        }
        $this->assertSame([], $sleeps);
        $this->assertSame(1, $endpoint->connections());
    }

    /** The API's certificate verifies and storage's does not: the PUT is made once and the upload is not completed. */
    public function testAnUploadDoesNotRepeatThePut(): void
    {
        $storage = $this->serve(TlsEndpoint::SELF_SIGNED);
        $api = $this->serve(TlsEndpoint::GOOD, 201, [
            'id' => 'upl-1',
            'transfer' => [
                'method' => 'single',
                'url' => $storage->url() . '/o?X-Amz-Signature=sig',
                'headers' => ['Content-Type' => 'video/mp4', 'Content-Length' => '5'],
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            ],
        ]);
        $this->paths[] = $path = sys_get_temp_dir() . '/' . uniqid('rask-', true) . '-a.mp4';
        file_put_contents($path, 'bytes');
        try {
            $this->rask($api, ['http_client' => self::trustingTheCa()], $sleeps)->uploads->uploadFile($path);
            $this->fail('Must raise.');
        } catch (UploadError $e) {
            $this->assertStringContainsString('after 1 attempts', $e->getMessage());
            $this->assertStringContainsString('cURL error 60:', $e->getMessage());
            $this->assertInstanceOf(ConnectionError::class, $e->getPrevious());
        }
        $this->assertSame([], $sleeps);
        $this->assertSame(1, $storage->connections());
        $this->assertSame(1, $api->connections(), 'createUpload, and no completeUpload after it.');
    }

    /** What stays retried: a handshake that breaks is a failure of the connection, and the next one may not break. */
    public function testAHandshakeThatBreaksIsStillRetried(): void
    {
        $endpoint = $this->serve(TlsEndpoint::HANGS_UP);
        try {
            $this->rask($endpoint, [], $sleeps)->account->getMe();
            $this->fail('Must raise.');
        } catch (ConnectionError $e) {
            $this->assertStringContainsString('cURL error 35:', $e->getMessage());
        }
        $this->assertGreaterThan(1, count($sleeps));
        $this->assertSame(count($sleeps) + 1, $endpoint->connections());
    }

    /**
     * Without cURL Guzzle opens the URL through PHP's stream wrapper, and calls any failure to open it a refused
     * connection: there is no code, and the message does not mention the certificate. The SDK cannot tell that from an
     * outage, so it retries, as it does whatever it does not recognise.
     */
    public function testWithoutCurlGuzzleReportsARefusedConnectionWhichIsRetried(): void
    {
        $endpoint = $this->serve(TlsEndpoint::SELF_SIGNED);
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create(new StreamHandler()), 'timeout' => 10]);
        try {
            $this->rask($endpoint, ['http_client' => $guzzle], $sleeps)->account->getMe();
            $this->fail('Must raise.');
        } catch (ConnectionError $e) {
            $this->assertStringContainsString('Connection refused', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('certificate', $e->getMessage());
        }
        $this->assertGreaterThan(1, count($sleeps));
        $this->assertSame(count($sleeps) + 1, $endpoint->connections());
    }
}
