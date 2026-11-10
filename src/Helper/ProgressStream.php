<?php

declare(strict_types=1);

namespace Rask\Sdk\Helper;

use Psr\Http\Message\StreamInterface;

/**
 * Counts what an HTTP client reads. A client may read in chunks, or take the small body whole as a string.
 *
 * @internal
 */
final class ProgressStream implements StreamInterface
{
    public int $sent = 0;

    public function __construct(
        private readonly StreamInterface $inner,
        private readonly int $total,
        private readonly ?\Closure $onProgress,
    ) {
    }

    public function read(int $length): string
    {
        $chunk = $this->inner->read($length);
        $this->report($this->sent + strlen($chunk));
        return $chunk;
    }

    public function getContents(): string
    {
        $contents = $this->inner->getContents();
        $this->report($this->sent + strlen($contents));
        return $contents;
    }

    public function __toString(): string
    {
        $this->rewind();
        return $this->getContents();
    }

    public function rewind(): void
    {
        $this->inner->rewind();
        $this->sent = 0;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
        $this->sent = $this->inner->tell();
    }

    public function report(int $sent): void
    {
        if ($sent === $this->sent) {
            return;
        }
        $this->sent = $sent;
        if ($this->onProgress !== null) {
            ($this->onProgress)($sent, $this->total);
        }
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): int
    {
        return $this->total;
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('This stream is read-only.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function getMetadata(?string $key = null): mixed
    {
        // No "uri": Guzzle would guess a Content-Type from it, and the signed one is the only one allowed.
        return $key === null ? [] : null;
    }
}
