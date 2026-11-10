<?php

declare(strict_types=1);

namespace Rask\Sdk;

use Rask\Sdk\Exception\UsageError;
use Rask\Sdk\Internal\Config;
use Rask\Sdk\Internal\ResourceProperties;
use Rask\Sdk\Internal\Transport;

/**
 * The Rask API v3 client.
 *
 *     $rask = new \Rask\Sdk\Rask(['api_key' => $key, 'base_url' => 'https://gw.example.com/rask/v3']);
 *     $me = $rask->account->getMe();
 *
 * Options:
 *
 * - api_key: string. Default: the RASK_API_KEY environment variable.
 * - base_url: string. Default: RASK_BASE_URL, then https://api.rask.ai/v3. A path prefix is kept, a trailing slash is
 *   fine. Applies to every operation and helper. The key goes only to URLs under it.
 * - timeout_seconds: int|float, per API request. Default 60.
 * - upload_timeout_seconds: int|float, per presigned PUT. Default 3600.
 * - http_client: a PSR-18 client, used for everything; it then owns the timeouts. Default: Guzzle.
 * - log: 'debug' for a traffic log with secrets hidden. Default: the RASK_LOG environment variable.
 * - logger: a PSR-3 logger for that log and for deprecation warnings. Default: stderr, and E_USER_DEPRECATED.
 * - on_deprecation: callable(string $operationId, array{deprecation: string, sunset: ?string, link: ?string}): void.
 * - sleeper: callable(int|float $seconds): void, clock: callable(): float. For tests that should not wait.
 */
final class Rask implements \Stringable
{
    use ResourceProperties;

    public const DEFAULT_BASE_URL = 'https://api.rask.ai/v3';

    private readonly Config $config;

    /**
     * @param array{
     *     api_key?: string,
     *     base_url?: string,
     *     timeout_seconds?: int|float,
     *     upload_timeout_seconds?: int|float,
     *     http_client?: \Psr\Http\Client\ClientInterface,
     *     log?: string,
     *     logger?: \Psr\Log\LoggerInterface,
     *     on_deprecation?: callable(string, array{deprecation: string, sunset: string|null, link: string|null}): void,
     *     sleeper?: callable(int|float): void,
     *     clock?: callable(): float,
     * } $options
     * @throws UsageError when no key is available or an option is wrong
     */
    public function __construct(#[\SensitiveParameter] array $options = [])
    {
        $this->config = Config::fromOptions($options);
        $this->initResources(new Transport($this->config));
    }

    public function baseUrl(): string
    {
        return $this->config->baseUrl;
    }

    public function timeoutSeconds(): int|float
    {
        return $this->config->timeoutSeconds;
    }

    public function __toString(): string
    {
        return "Rask(base_url={$this->config->baseUrl})";
    }

    /** @return array{base_url: string, timeout_seconds: int|float} */
    public function __debugInfo(): array
    {
        return ['base_url' => $this->config->baseUrl, 'timeout_seconds' => $this->config->timeoutSeconds];
    }

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('A Rask client cannot be serialized: it holds the API key.');
    }
}
