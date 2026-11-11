<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

/**
 * A stream wrapper without stream_seek, as "gs://" and others are. PHP says every stream of a user-space wrapper seeks,
 * and learns otherwise only when a seek fails. The bytes are the host of the URL: fopen('scheme://bytes', 'rb').
 */
final class UnseekableStreamWrapper
{
    /** @var resource|null */
    public $context;

    private string $bytes = '';

    private int $position = 0;

    public function stream_open(string $path): bool
    {
        $this->bytes = (string) parse_url($path, PHP_URL_HOST);
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->bytes, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->bytes);
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }
}
