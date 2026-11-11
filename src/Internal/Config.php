<?php

declare(strict_types=1);

namespace Rask\Sdk\Internal;

use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use Rask\Sdk\Exception\UsageError;
use Rask\Sdk\Rask;

/** @internal */
final class Config
{
    private const KNOWN_OPTIONS = [
        'api_key', 'base_url', 'timeout_seconds', 'upload_timeout_seconds', 'http_client', 'logger', 'log', 'on_deprecation', 'sleeper', 'clock',
    ];

    private function __construct(
        public readonly Secret $apiKey,
        public readonly string $baseUrl,
        public readonly int|float $timeoutSeconds,
        public readonly ClientInterface $http,
        public readonly ClientInterface $uploadHttp,
        public readonly DebugLog $log,
        public readonly ?LoggerInterface $logger,
        public readonly ?\Closure $onDeprecation,
        public readonly \Closure $sleeper,
        public readonly \Closure $clock,
    ) {
    }

    /** @param array<string, mixed> $options */
    public static function fromOptions(#[\SensitiveParameter] array $options): self
    {
        $unknown = array_diff(array_keys($options), self::KNOWN_OPTIONS);
        if ($unknown !== []) {
            throw new UsageError('Unknown Rask option: ' . implode(', ', $unknown) . '. Known: ' . implode(', ', self::KNOWN_OPTIONS) . '.');
        }

        $apiKey = $options['api_key'] ?? self::env('RASK_API_KEY');
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new UsageError("No API key: pass 'api_key' to new Rask([...]) or set RASK_API_KEY.");
        }
        $apiKey = trim($apiKey);
        // The PSR-7 request quotes an invalid header value in its exception, so a bad key must stop here.
        if (preg_match('/^[\x21-\x7E]+$/D', $apiKey) !== 1) {
            throw new UsageError('The API key holds spaces or control characters: check how it is read.');
        }

        $baseUrl = $options['base_url'] ?? self::env('RASK_BASE_URL') ?? Rask::DEFAULT_BASE_URL;
        $timeout = self::seconds($options, 'timeout_seconds', 60);
        $uploadTimeout = self::seconds($options, 'upload_timeout_seconds', 3600);

        $http = $options['http_client'] ?? null;
        if ($http !== null && !$http instanceof ClientInterface) {
            throw new UsageError("'http_client' must implement Psr\\Http\\Client\\ClientInterface.");
        }
        $logger = $options['logger'] ?? null;
        if ($logger !== null && !$logger instanceof LoggerInterface) {
            throw new UsageError("'logger' must implement Psr\\Log\\LoggerInterface.");
        }
        $onDeprecation = $options['on_deprecation'] ?? null;
        if ($onDeprecation !== null && !is_callable($onDeprecation)) {
            throw new UsageError("'on_deprecation' must be callable.");
        }
        $sleeper = $options['sleeper'] ?? null;
        if ($sleeper !== null && !is_callable($sleeper)) {
            throw new UsageError("'sleeper' must be callable.");
        }
        $clock = $options['clock'] ?? null;
        if ($clock !== null && !is_callable($clock)) {
            throw new UsageError("'clock' must be callable.");
        }

        $level = $options['log'] ?? self::env('RASK_LOG');

        return new self(
            new Secret($apiKey),
            self::normalizeBaseUrl($baseUrl),
            $timeout,
            $http ?? self::guzzle($timeout),
            $http ?? self::guzzle($uploadTimeout),
            new DebugLog($level === 'debug', $logger),
            $logger,
            $onDeprecation === null ? null : \Closure::fromCallable($onDeprecation),
            $sleeper === null
                ? static function (int|float $seconds): void {
                    // usleep() takes its microseconds as 32 bits: a wait past 71 minutes would wrap round into a
                    // short one.
                    $whole = (int) floor($seconds);
                    sleep($whole);
                    usleep((int) round(($seconds - $whole) * 1_000_000));
                }
                : \Closure::fromCallable($sleeper),
            $clock === null ? static fn (): float => hrtime(true) / 1e9 : \Closure::fromCallable($clock),
        );
    }

    /** PSR-18 mode already follows no redirects and raises on no status: Transport owns both. */
    private static function guzzle(int|float $timeout): ClientInterface
    {
        return new GuzzleClient(['timeout' => $timeout, 'connect_timeout' => min(10, $timeout)]);
    }

    /** Laravel and Symfony load .env into $_ENV or $_SERVER, not always through putenv(). */
    private static function env(string $name): ?string
    {
        foreach ([$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)] as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $options */
    private static function seconds(array $options, string $name, int $default): int|float
    {
        $value = $options[$name] ?? $default;
        if ((!is_int($value) && !is_float($value)) || $value <= 0) {
            throw new UsageError("'$name' must be a positive number of seconds.");
        }
        return $value;
    }

    private static function normalizeBaseUrl(#[\SensitiveParameter] mixed $value): string
    {
        if (!is_string($value)) {
            throw new UsageError("'base_url' must be a string.");
        }
        $url = rtrim(trim($value), '/');
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            throw new UsageError('base_url must be an absolute http or https URL, like ' . Rask::DEFAULT_BASE_URL . ". Got: $url");
        }
        if (isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new UsageError('base_url cannot hold a query, a fragment or credentials.');
        }
        return $url;
    }
}
