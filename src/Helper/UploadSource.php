<?php

declare(strict_types=1);

namespace Rask\Sdk\Helper;

use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Rask\Sdk\Exception\UsageError;

/**
 * What uploads->uploadFile() takes, as a rewindable stream of known size. A whole-file retry needs both, so a pipe, a
 * socket or any other stream that does not go back to its start is spooled to a temp file first.
 *
 * @internal
 */
final class UploadSource
{
    /** Content types the API accepts, by file extension. */
    private const CONTENT_TYPES = [
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'qt' => 'video/quicktime',
        'mkv' => 'video/x-matroska',
        'webm' => 'video/webm',
        'avi' => 'video/x-msvideo',
        'm4v' => 'video/x-m4v',
        '3gp' => 'video/3gpp',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'm4a' => 'audio/mp4',
    ];

    private function __construct(
        public readonly StreamInterface $stream,
        public readonly int $size,
        public readonly string $filename,
        public readonly string $contentType,
        private readonly bool $ownsStream,
        private readonly bool $ownsResource,
    ) {
    }

    /**
     * @param string|resource|StreamInterface $source
     * @param array<string, mixed> $params
     */
    public static function from(mixed $source, array $params): self
    {
        $filename = self::stringParam($params, 'filename');
        $contentType = self::stringParam($params, 'content_type');

        if (is_string($source)) {
            if (!is_file($source) || !is_readable($source)) {
                throw new UsageError("uploadFile: cannot read the file $source.");
            }
            $handle = fopen($source, 'rb');
            if ($handle === false) {
                throw new UsageError("uploadFile: cannot open the file $source.");
            }
            $filename ??= basename($source);
            $contentType ??= self::CONTENT_TYPES[strtolower(pathinfo($source, PATHINFO_EXTENSION))]
                ?? throw new UsageError("uploadFile: cannot tell the content type of $source: pass content_type.");
            return self::measured(new Stream($handle), $filename, $contentType, true, true);
        }

        if ($filename === null || $contentType === null) {
            throw new UsageError('uploadFile: a stream needs filename and content_type.');
        }
        if ($source instanceof StreamInterface) {
            return self::measured($source, $filename, $contentType, false, false);
        }
        if (is_resource($source)) {
            return self::measured(new Stream($source), $filename, $contentType, true, false);
        }
        throw new UsageError('uploadFile takes a path, a stream resource or a PSR-7 stream; got ' . get_debug_type($source) . '.');
    }

    /** Lets go of the stream without closing what the caller still owns. */
    public function release(): void
    {
        if (!$this->ownsStream) {
            return;
        }
        if ($this->ownsResource) {
            $this->stream->close();
        } else {
            $this->stream->detach();
        }
    }

    private static function measured(StreamInterface $stream, string $filename, string $contentType, bool $ownsStream, bool $ownsResource): self
    {
        if (!self::rewinds($stream)) {
            $spool = Utils::streamFor(fopen('php://temp/maxmemory:8388608', 'r+b'));
            Utils::copyToStream($stream, $spool);
            if ($ownsStream && !$ownsResource) {
                $stream->detach();
            }
            $stream = $spool;
            $ownsStream = true;
            $ownsResource = true;
        }
        $size = $stream->getSize();
        if ($size === null) {
            // A stream that seeks but does not say its size.
            $stream->seek(0, SEEK_END);
            $size = $stream->tell();
        }
        $stream->rewind();
        if ($size < 1) {
            if ($ownsStream && !$ownsResource) {
                $stream->detach();
            }
            throw new UsageError("uploadFile: $filename is empty.");
        }
        return new self($stream, $size, $filename, $contentType, $ownsStream, $ownsResource);
    }

    /**
     * A stream may say it seeks and then fail to: PHP says so of every stream of a user-space wrapper, whether or not
     * the wrapper can seek. Such a stream is spooled like a pipe, as long as nothing has been read from it: one that
     * has been read from cannot be sent from its start, and what is left of it would pass for the whole file.
     */
    private static function rewinds(StreamInterface $stream): bool
    {
        if (!$stream->isSeekable()) {
            return false;
        }
        try {
            // PHP warns of the seek that fails before the stream raises.
            @$stream->rewind();
            return true;
        } catch (\RuntimeException) {
            try {
                $read = $stream->tell();
            } catch (\RuntimeException) {
                // A stream that cannot say where it is gets the benefit of the doubt, like a pipe.
                $read = 0;
            }
            if ($read > 0) {
                throw new UsageError('The stream does not go back to its start and has already been read from, so it cannot be uploaded whole.');
            }
            return false;
        }
    }

    /** @param array<string, mixed> $params */
    private static function stringParam(array $params, string $name): ?string
    {
        $value = $params[$name] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new UsageError("uploadFile: $name must be a string.");
        }
        return $value;
    }
}
