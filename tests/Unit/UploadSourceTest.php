<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Rask\Sdk\Exception\UploadError;
use Rask\Sdk\Exception\UsageError;
use Rask\Sdk\Helper\UploadSource;

final class UploadSourceTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    private function file(string $name, string $bytes): string
    {
        $path = sys_get_temp_dir() . '/' . uniqid('rask-', true) . '-' . $name;
        file_put_contents($path, $bytes);
        $this->paths[] = $path;
        return $path;
    }

    private static function created(): \GuzzleHttp\Psr7\Response
    {
        return FakeHttpClient::json(201, [
            'id' => 'upl-1',
            'transfer' => [
                'method' => 'single',
                'url' => 'https://bucket.example/o?X-Amz-Signature=sig',
                'headers' => ['Content-Type' => 'video/mp4', 'Content-Length' => '5'],
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            ],
        ]);
    }

    public function testAPathGivesNameTypeAndSize(): void
    {
        $path = $this->file('talk.MP4', 'bytes');
        $source = UploadSource::from($path, []);
        $this->assertSame(5, $source->size);
        $this->assertSame(basename($path), $source->filename);
        $this->assertSame('video/mp4', $source->contentType);
        $source->release();
    }

    public function testAnUnknownExtensionNeedsContentType(): void
    {
        $this->expectExceptionMessage('content_type');
        UploadSource::from($this->file('talk.xyz', 'bytes'), []);
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectException(UsageError::class);
        UploadSource::from($this->file('talk.mp4', ''), []);
    }

    public function testAMissingFileIsRefused(): void
    {
        $this->expectException(UsageError::class);
        UploadSource::from('/does/not/exist.mp4', []);
    }

    public function testAStreamNeedsNameAndType(): void
    {
        $this->expectExceptionMessage('filename and content_type');
        UploadSource::from(fopen('php://memory', 'r+b'), []);
    }

    public function testACallersResourceStaysOpen(): void
    {
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, 'bytes');
        $source = UploadSource::from($stream, ['filename' => 'a.mp4', 'content_type' => 'video/mp4']);
        $this->assertSame(5, $source->size);
        $source->release();
        unset($source);
        gc_collect_cycles();
        $this->assertTrue(is_resource($stream));
        fclose($stream);
    }

    public function testAnEmptyStreamIsRefusedAndStaysOpen(): void
    {
        $stream = fopen('php://temp', 'r+b');
        try {
            UploadSource::from($stream, ['filename' => 'a.mp4', 'content_type' => 'video/mp4']);
            $this->fail('Must raise.');
        } catch (UsageError) {
            gc_collect_cycles();
            $this->assertTrue(is_resource($stream));
        }
        fclose($stream);
    }

    public function testAPipeIsSpooledSoItCanBeRetried(): void
    {
        $pipe = popen('printf bytes', 'r');
        $source = UploadSource::from($pipe, ['filename' => 'a.mp4', 'content_type' => 'video/mp4']);
        $this->assertSame(5, $source->size);
        $this->assertTrue($source->stream->isSeekable());
        $this->assertSame('bytes', (string) $source->stream);
        $source->release();
        pclose($pipe);
    }

    /** A stream may say it seeks and then fail to. It is copied like a pipe, and the caller's stream stays open. */
    public function testAResourceThatSaysItSeeksAndCannotIsSpooledSoItCanBeRetried(): void
    {
        stream_wrapper_register('rask-unseekable', UnseekableStreamWrapper::class);
        try {
            $stream = fopen('rask-unseekable://bytes', 'rb');
            $this->assertTrue(stream_get_meta_data($stream)['seekable']);
            $source = UploadSource::from($stream, ['filename' => 'a.mp4', 'content_type' => 'video/mp4']);
            $this->assertSame(5, $source->size);
            $this->assertSame('bytes', (string) $source->stream);
            $this->assertSame('bytes', (string) $source->stream);
            $source->release();
            $this->assertTrue(is_resource($stream));
            fclose($stream);
        } finally {
            stream_wrapper_unregister('rask-unseekable');
        }
    }

    /** The same from a PSR-7 stream, through a whole upload: storage fails the first PUT and gets the file again. */
    public function testAPsr7StreamThatSaysItSeeksAndCannotIsSentWholeOnEveryAttempt(): void
    {
        $stream = new class (Utils::streamFor('bytes')) implements StreamInterface {
            use StreamDecoratorTrait;

            /** @var StreamInterface */
            private $stream;

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new \RuntimeException('This stream does not seek after all.');
            }
        };
        $this->assertTrue($stream->isSeekable());
        $http = new FakeHttpClient([self::created(), new Response(503), new Response(200), FakeHttpClient::json(202, ['id' => 'job-1'])]);
        $job = $http->client()->uploads->uploadFile($stream, ['filename' => 'a.mp4', 'content_type' => 'video/mp4']);
        $this->assertSame('job-1', $job->id);
        $this->assertSame(['PUT', 'PUT'], [$http->requests[1]->getMethod(), $http->requests[2]->getMethod()]);
        $this->assertSame(['bytes', 'bytes'], [$http->bodies[1], $http->bodies[2]]);
    }

    public function testAPsr7StreamStaysOpen(): void
    {
        $stream = Utils::streamFor('bytes');
        $source = UploadSource::from($stream, ['filename' => 'a.mp4', 'content_type' => 'video/mp4']);
        $source->release();
        $this->assertSame('bytes', (string) $stream);
    }

    public function testAnUnknownTransferMethodIsAClearError(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(201, ['id' => 'u', 'transfer' => ['method' => 'multipart']])]);
        try {
            $http->client()->uploads->uploadFile($this->file('a.mp4', 'bytes'));
            $this->fail('Must raise.');
        } catch (UploadError $e) {
            $this->assertStringContainsString('multipart', $e->getMessage());
        }
    }

    public function testAForbiddenPutIsNotRetried(): void
    {
        $http = new FakeHttpClient([self::created(), new Response(403, [], '<Error><Code>SignatureDoesNotMatch</Code></Error>')]);
        try {
            $http->client()->uploads->uploadFile($this->file('a.mp4', 'bytes'));
            $this->fail('Must raise.');
        } catch (UploadError $e) {
            $this->assertSame(403, $e->status);
            $this->assertCount(2, $http->requests);
        }
    }

    /**
     * Nothing else bounds the wait that storage names. One past the 120 s of an API call ends the retries, as it does
     * there.
     */
    public function testAStorageRetryAfterPastTheBudgetEndsTheUploadAtOnce(): void
    {
        $http = new FakeHttpClient([self::created(), new Response(503, ['Retry-After' => '86400']), new Response(200), FakeHttpClient::json(202, [])]);
        try {
            $http->client([], $sleeps)->uploads->uploadFile($this->file('a.mp4', 'bytes'));
            $this->fail('Must raise.');
        } catch (UploadError $e) {
            $this->assertSame(503, $e->status);
            $this->assertSame(86400, $e->retry_after);
        }
        $this->assertSame([], $sleeps);
        $this->assertCount(2, $http->requests);
    }

    /** A connection that fails may work on the next attempt. A certificate will not: CertificateTest has that one. */
    public function testAPutThatGotNoAnswerIsRepeated(): void
    {
        $http = new FakeHttpClient([self::created(), FakeHttpClient::connectionLost(), new Response(200), FakeHttpClient::json(202, ['id' => 'job-1'])]);
        $job = $http->client([], $sleeps)->uploads->uploadFile($this->file('a.mp4', 'bytes'));
        $this->assertSame('job-1', $job->id);
        $this->assertCount(1, $sleeps);
        $this->assertCount(4, $http->requests);
    }

    public function testAStorageRetryAfterWithinTheBudgetIsHonoured(): void
    {
        $http = new FakeHttpClient([self::created(), new Response(503, ['Retry-After' => '45']), new Response(200), FakeHttpClient::json(202, [])]);
        $http->client([], $sleeps)->uploads->uploadFile($this->file('a.mp4', 'bytes'));
        $this->assertSame([45.0], $sleeps);
        $this->assertCount(4, $http->requests);
    }

    public function testThePutCarriesExactlyTheSignedHeadersAndNoKey(): void
    {
        $http = new FakeHttpClient([self::created(), new Response(200), FakeHttpClient::json(202, ['id' => 'job-1'])]);
        $job = $http->client()->uploads->uploadFile($this->file('a.mp4', 'bytes'));
        $put = $http->requests[1];
        $this->assertSame('PUT', $put->getMethod());
        $this->assertFalse($put->hasHeader('Authorization'));
        $this->assertFalse($put->hasHeader('X-Rask-Client'));
        $this->assertFalse($put->hasHeader('Idempotency-Key'));
        $this->assertSame('video/mp4', $put->getHeaderLine('Content-Type'));
        $this->assertSame('5', $put->getHeaderLine('Content-Length'));
        $this->assertSame('https://bucket.example/o?X-Amz-Signature=sig', (string) $put->getUri());
        $this->assertSame('bytes', $http->bodies[1]);
        $this->assertSame('job-1', $job->id);
    }

    public function testAResignLoopEnds(): void
    {
        $expired = new Response(403, [], '<Error><Message>Request has expired</Message></Error>');
        $http = new FakeHttpClient([self::created(), $expired, self::created(), $expired, self::created(), $expired, self::created(), $expired]);
        $this->expectException(UploadError::class);
        $http->client()->uploads->uploadFile($this->file('a.mp4', 'bytes'));
    }

    public function testAnUnknownParameterIsRefused(): void
    {
        $this->expectExceptionMessage('onProgress');
        (new FakeHttpClient())->client()->uploads->uploadFile($this->file('a.mp4', 'bytes'), ['onProgress' => null]);
    }
}
