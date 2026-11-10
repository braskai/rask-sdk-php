<?php

declare(strict_types=1);

namespace Rask\Sdk\Internal;

/** @internal */
final class Redact
{
    /** Hides signature-like query values, as in a presigned URL. */
    public static function url(#[\SensitiveParameter] string $url): string
    {
        return (string) preg_replace_callback(
            '/([?&])([^=&#]*(?:signature|sig|credential|token|secret|key|policy)[^=&#]*)=[^&#\s]*/i',
            static fn (array $match): string => "{$match[1]}{$match[2]}=***",
            $url,
        );
    }

    /** Hides the key and presigned URLs inside a message from an HTTP client. */
    public static function message(string $message, Secret $key): string
    {
        $message = str_replace($key->reveal(), '***', $message);
        return (string) preg_replace_callback(
            '~https?://[^\s"\'<>)]+~i',
            static fn (array $match): string => self::url($match[0]),
            $message,
        );
    }
}
