<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Rask\Sdk\Exception\ConnectionError;
use Rask\Sdk\Exception\RaskError;
use Rask\Sdk\Exception\UploadError;
use Rask\Sdk\Internal\Redact;
use Rask\Sdk\Internal\Secret;
use Rask\Sdk\Rask;
use Rask\Sdk\Tests\Acceptance\CapturingLogger;

final class RedactionTest extends TestCase
{
    private const KEY = 'raskai_test_key_unit';

    private static function dump(mixed $value): string
    {
        ob_start();
        var_dump($value);
        return (string) ob_get_clean() . print_r($value, true) . var_export($value, true);
    }

    public function testDumpsOfTheClientAndItsResourcesHideTheKey(): void
    {
        $rask = new Rask(['api_key' => self::KEY, 'base_url' => 'https://gw.example.com/v3']);
        foreach ([$rask, $rask->jobs, $rask->uploads] as $object) {
            $this->assertStringNotContainsString(self::KEY, self::dump($object));
        }
        $this->assertStringNotContainsString(self::KEY, (string) $rask);
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($rask));
        $this->assertStringNotContainsString(self::KEY, var_export((array) $rask, true));
    }

    public function testTheClientRefusesToSerialize(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new Rask(['api_key' => self::KEY]));
    }

    public function testAConstructorFailureHidesTheKeyFromTheStackTrace(): void
    {
        try {
            new Rask(['api_key' => self::KEY, 'base_url' => 'nope']);
            $this->fail('Must raise.');
        } catch (\Throwable $e) {
            $this->assertStringNotContainsString(self::KEY, print_r($e->getTrace(), true));
            $this->assertStringNotContainsString(self::KEY, $e->getTraceAsString());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function testNoErrorHoldsTheRequest(): void
    {
        $http = new FakeHttpClient([new Response(404)]);
        try {
            $http->client()->account->getMe();
            $this->fail('Must raise.');
        } catch (RaskError $e) {
            $this->assertStringNotContainsString(self::KEY, print_r($e, true));
            $this->assertStringNotContainsString(self::KEY, (string) $e);
        }
    }

    /** Guzzle's exception holds the request, Authorization included. It must not hide in the trace's arguments. */
    public function testAConnectionErrorOnAnApiCallHoldsNoSecret(): void
    {
        $message = 'cURL error 7: refused for https://bucket.example/o?X-Amz-Signature=abc123&X-Amz-Date=1 with ' . self::KEY;
        $logger = new CapturingLogger();
        $now = 0.0;
        $rask = new Rask([
            'api_key' => self::KEY,
            'base_url' => 'https://gw.example.com/v3',
            'http_client' => new ThrowingHttpClient($message),
            'log' => 'debug',
            'logger' => $logger,
            'sleeper' => static function (int|float $seconds) use (&$now): void {
                $now += $seconds;
            },
            'clock' => static function () use (&$now): float {
                return $now;
            },
        ]);
        try {
            $rask->account->getMe();
            $this->fail('Must raise.');
        } catch (ConnectionError $e) {
            foreach ([self::KEY, 'abc123'] as $secret) {
                $this->assertStringNotContainsString($secret, $e->getMessage());
                $this->assertStringNotContainsString($secret, (string) $e);
                $this->assertStringNotContainsString($secret, print_r($e->getTrace(), true));
                $this->assertStringNotContainsString($secret, print_r($e, true));
            }
            $this->assertNull($e->getPrevious(), 'A chained client exception would hold the request and its Authorization.');
        }
        $this->assertStringNotContainsString(self::KEY, implode("\n", $logger->lines));
        $this->assertStringNotContainsString('abc123', implode("\n", $logger->lines));
    }

    public function testAConnectionErrorOnAPutHidesThePresignedUrl(): void
    {
        $rask = new Rask([
            'api_key' => self::KEY,
            'base_url' => 'https://gw.example.com/v3',
            'http_client' => new ThrowingHttpClient('cURL error 7: refused'),
        ]);
        $transport = (new \ReflectionProperty($rask->uploads, 'transport'))->getValue($rask->uploads);
        try {
            $transport->putToStorage('https://bucket.example/o?X-Amz-Signature=abc123', [], \GuzzleHttp\Psr7\Utils::streamFor('bytes'));
            $this->fail('Must raise.');
        } catch (ConnectionError $e) {
            $this->assertStringNotContainsString('abc123', print_r($e, true));
        }
    }

    public function testAFailedUploadHoldsNoPresignedUrlInItsTrace(): void
    {
        $upload = [
            'object' => 'upload',
            'id' => 'u1',
            'status' => 'pending',
            'filename' => 'a.mp4',
            'content_type' => 'video/mp4',
            'size_bytes' => 5,
            'purpose' => 'project_source',
            'transfer' => [
                'method' => 'single',
                'url' => 'https://bucket.example/o?X-Amz-Signature=abc123',
                'headers' => ['Content-Type' => 'video/mp4', 'Content-Length' => '5'],
                'expires_at' => '2099-01-01T00:00:00Z',
            ],
        ];
        $http = new FakeHttpClient([FakeHttpClient::json(201, $upload), new Response(500), new Response(500), new Response(500), new Response(500)]);
        $path = tempnam(sys_get_temp_dir(), 'rask');
        file_put_contents($path, 'bytes');
        try {
            $http->client()->uploads->uploadFile($path, ['filename' => 'a.mp4', 'content_type' => 'video/mp4']);
            $this->fail('Must raise.');
        } catch (UploadError $e) {
            $this->assertStringNotContainsString('abc123', print_r($e, true));
            $this->assertStringNotContainsString('abc123', (string) $e);
        } finally {
            unlink($path);
        }
    }

    public function testABaseUrlRefusedForItsCredentialsLeavesThemOutOfTheTrace(): void
    {
        try {
            new Rask(['api_key' => self::KEY, 'base_url' => 'https://user:topsecret@gw.example.com/v3']);
            $this->fail('Must raise.');
        } catch (\Rask\Sdk\Exception\UsageError $e) {
            $this->assertStringNotContainsString('topsecret', print_r($e, true));
            $this->assertStringNotContainsString('topsecret', (string) $e);
        }
    }

    public function testATimeoutIsATimeoutErrorAndHoldsNoSecret(): void
    {
        $rask = new Rask([
            'api_key' => self::KEY,
            'base_url' => 'https://gw.example.com/v3',
            'http_client' => new ThrowingHttpClient('cURL error 28: Operation timed out'),
        ]);
        $transport = (new \ReflectionProperty($rask->uploads, 'transport'))->getValue($rask->uploads);
        try {
            $transport->putToStorage('https://bucket.example/o', [], \GuzzleHttp\Psr7\Utils::streamFor('bytes'));
            $this->fail('Must raise.');
        } catch (\Rask\Sdk\Exception\TimeoutError $e) {
            $this->assertStringNotContainsString(self::KEY, print_r($e, true));
        }
    }

    public function testUrlRedaction(): void
    {
        $url = 'https://s3.example/k?X-Amz-Credential=AKIA%2Fx&X-Amz-Date=20261103&X-Amz-Security-Token=tok&X-Amz-Signature=sig1&cursor=c1';
        $redacted = Redact::url($url);
        foreach (['AKIA', 'tok', 'sig1'] as $secret) {
            $this->assertStringNotContainsString($secret, $redacted);
        }
        $this->assertStringContainsString('X-Amz-Date=20261103', $redacted);
        $this->assertStringContainsString('cursor=c1', $redacted);
    }

    public function testSecretShowsNowhere(): void
    {
        $secret = new Secret('s3cr3t');
        $this->assertSame('s3cr3t', $secret->reveal());
        $this->assertStringNotContainsString('s3cr3t', self::dump($secret));
        $this->assertStringNotContainsString('s3cr3t', (string) $secret);
        $this->assertStringNotContainsString('s3cr3t', (string) json_encode($secret));
    }

    public function testDebugLogNamesTheRequestAndHidesTheKey(): void
    {
        $logger = new CapturingLogger();
        $http = new FakeHttpClient([new Response(503), FakeHttpClient::json(200, [])]);
        $http->client(['log' => 'debug', 'logger' => $logger])->account->getMe();
        $log = implode("\n", $logger->lines);
        $this->assertStringContainsString('> GET https://gw.example.com/rask/v3/me', $log);
        $this->assertStringContainsString('Authorization: Bearer ***', $log);
        $this->assertStringContainsString('< 503 GET', $log);
        $this->assertStringContainsString('retrying getMe', $log);
        $this->assertStringNotContainsString(self::KEY, $log);
    }

    public function testNoDebugLogUnlessAskedFor(): void
    {
        $logger = new CapturingLogger();
        $http = new FakeHttpClient();
        $http->client(['logger' => $logger])->account->getMe();
        $this->assertSame([], $logger->lines);
    }
}
