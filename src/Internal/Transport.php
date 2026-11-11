<?php

declare(strict_types=1);

namespace Rask\Sdk\Internal;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Rask\Sdk\Core\RawResponse;
use Rask\Sdk\Core\Version;
use Rask\Sdk\Exception\ConnectionError;
use Rask\Sdk\Exception\RaskError;
use Rask\Sdk\Exception\TimeoutError;
use Rask\Sdk\Exception\UnexpectedResponseError;
use Rask\Sdk\Exception\UsageError;

/**
 * Every HTTP exchange goes through here. URLs are built by concatenating base_url and the contract's path, never by URL
 * resolution, so a base_url path prefix survives; the only absolute URLs it takes are presigned ones.
 *
 * @internal
 */
final class Transport
{
    public const RETRY_STATUSES = [429, 502, 503, 504];
    public const BUDGET_SECONDS = 120.0;

    private const OPTION_NAMES = ['if_match', 'if_none_match', 'idempotency_key'];
    private const MAX_KEY_IN_USE_RETRIES = 3;
    private const MAX_REDIRECTS = 5;

    /**
     * cURL's codes for a certificate that fails verification: self-signed, of an unknown issuer, expired, or for another
     * host name. libcurl gives 60 for all of them; before 7.62 it gave 51 for the host name.
     */
    private const CERTIFICATE_ERRNOS = [51, 60];

    private readonly string $userAgent;

    private readonly string $clientName;

    /** @var array<string, true> */
    private array $warned = [];

    public function __construct(private readonly Config $config)
    {
        $this->clientName = 'rask-sdk-php/' . Version::VERSION;
        $this->userAgent = $this->clientName . ' php/' . PHP_VERSION;
    }

    /**
     * One logical API call: key, retries, redirects, errors.
     *
     * @param array<string, string|int> $path
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed>|null $body
     * @param array<array-key, mixed> $options
     */
    public function call(string $operationId, array $path, array $query, ?array $body, array $options): RawResponse
    {
        $operation = Operations::ALL[$operationId];
        $this->checkOptions($operationId, $operation, $options);

        $url = $this->config->baseUrl . self::fillPath($operationId, $operation['path'], $path) . Encoder::query($query);
        $headers = ['Accept' => 'application/json', 'User-Agent' => $this->userAgent, 'X-Rask-Client' => $this->clientName];

        $key = null;
        if ($operation['idempotency'] !== 'none') {
            $key = self::stringOption($options, 'idempotency_key') ?? Uuid::v4();
            $headers['Idempotency-Key'] = $key;
        }
        $ifMatch = self::stringOption($options, 'if_match');
        if ($ifMatch !== null) {
            $headers['If-Match'] = $ifMatch;
        }
        $ifNoneMatch = self::stringOption($options, 'if_none_match');
        if ($ifNoneMatch !== null) {
            $headers['If-None-Match'] = $ifNoneMatch;
        }

        $payload = null;
        if ($operation['body'] !== null && $body !== null && ($operation['body_required'] || $body !== [])) {
            $payload = Encoder::body($body, $operation['body']);
            $headers['Content-Type'] = 'application/json';
        }

        return $this->withRetries($operationId, $operation['method'], $url, $headers, $payload, $key, $ifNoneMatch !== null);
    }

    /**
     * One PUT to a presigned URL, with no Authorization, through any redirect. The caller retries.
     *
     * @param array<string, string> $signedHeaders
     * @throws ConnectionError
     */
    public function putToStorage(#[\SensitiveParameter] string $url, array $signedHeaders, StreamInterface $body): RawResponse
    {
        return $this->exchange('PUT', $url, $signedHeaders, $body, null, false);
    }

    /** Seconds on a monotonic clock. */
    public function now(): float
    {
        /** @var float */
        return ($this->config->clock)();
    }

    public function pause(int|float $seconds): void
    {
        if ($seconds > 0) {
            ($this->config->sleeper)($seconds);
        }
    }

    /** S3: exponential 0.5 s to 30 s, factor 1.5, with jitter. */
    public static function backoff(int $attempt): float
    {
        $base = min(0.5 * 1.5 ** ($attempt - 1), 30.0);
        return $base * (0.75 + random_int(0, 1000) / 2000);
    }

    /**
     * @param array<string, string> $headers
     */
    private function withRetries(string $operationId, string $method, string $url, array $headers, ?string $payload, ?string $key, bool $conditional): RawResponse
    {
        $started = $this->now();
        $attempt = 0;
        $keyInUse = 0;
        while (true) {
            ++$attempt;
            try {
                $response = $this->exchange($method, $url, $headers, $payload, $key, true);
            } catch (ConnectionError $error) {
                // The next attempt would fail the same way: a retry would only report it later.
                if (!$error->mayPass()) {
                    throw $error;
                }
                $this->waitForRetry($error, self::backoff($attempt), $started, $operationId, $attempt);
                continue;
            }
            $this->noteDeprecation($operationId, $response);
            if (($response->status >= 200 && $response->status < 300) || ($response->status === 304 && $conditional)) {
                return $response;
            }
            $error = ErrorFactory::fromResponse($response);
            $retry = in_array($response->status, self::RETRY_STATUSES, true);
            if ($response->status === 409 && $error->code === 'idempotency_key_in_use') {
                $retry = $keyInUse++ < self::MAX_KEY_IN_USE_RETRIES;
            }
            if (!$retry) {
                throw $error;
            }
            $this->waitForRetry($error, max(self::backoff($attempt), (float) ($response->retryAfter() ?? 0)), $started, $operationId, $attempt);
        }
    }

    /** Sleeps before the next attempt, or raises the last failure if the wait would leave the budget. */
    private function waitForRetry(RaskError $error, float $wait, float $started, string $operationId, int $attempt): void
    {
        if ($this->now() - $started + $wait > self::BUDGET_SECONDS) {
            throw $error;
        }
        $this->config->log->write(sprintf('retrying %s in %.2f s (attempt %d failed: %s)', $operationId, $wait, $attempt, $error->status ?: $error::class));
        $this->pause($wait);
    }

    /**
     * Sends one request and follows redirects by hand. PSR-18 clients do not follow them: this is where the key is
     * withheld from any other origin.
     *
     * An exception's stack trace keeps the arguments of every frame, and print_r() shows them. So the Authorization
     * header is added here and nowhere above, and neither the HTTP client (which may keep its requests) nor its exception
     * (which holds the request) is ever passed as an argument on the way to a ConnectionError.
     *
     * @param array<string, string> $headers
     * @throws ConnectionError
     */
    private function exchange(string $method, #[\SensitiveParameter] string $url, array $headers, string|StreamInterface|null $body, ?string $key, bool $authorize): RawResponse
    {
        $http = $authorize ? $this->config->http : $this->config->uploadHttp;
        $origin = new Uri($url);
        $hops = 0;
        while (true) {
            $started = $this->now();
            $this->config->log->write(sprintf('> %s %s%s', $method, Redact::url($url), $authorize ? ' Authorization: Bearer ***' : ''));
            if ($body instanceof StreamInterface && $body->isSeekable()) {
                $body->rewind();
            }
            $request = new Request($method, $url, $headers, is_string($body) ? Utils::streamFor($body) : $body);
            if ($authorize) {
                $request = $request->withHeader('Authorization', 'Bearer ' . $this->config->apiKey->reveal());
            }
            try {
                $psrResponse = $http->sendRequest($request);
                $text = (string) $psrResponse->getBody();
            } catch (ClientExceptionInterface | \RuntimeException $e) {
                // Only cURL's code tells a certificate from an outage, and only Guzzle on cURL has one. Without cURL
                // Guzzle calls every failure to connect a refused connection, and another client has its own words.
                // What is not recognised may pass: when in doubt, it is retried.
                $mayPass = !(($e instanceof RequestException || $e instanceof ConnectException)
                    && in_array($e->getHandlerContext()['errno'] ?? null, self::CERTIFICATE_ERRNOS, true));
                throw self::connectionError(Redact::message($e->getMessage(), $this->config->apiKey), $mayPass, $method, $url, $key);
            }
            $response = new RawResponse($psrResponse->getStatusCode(), self::flatHeaders($psrResponse), $text, $key);
            $this->config->log->write(sprintf(
                '< %d %s %s %d ms%s',
                $response->status,
                $method,
                Redact::url($url),
                (int) round(($this->now() - $started) * 1000),
                $response->request_id === null ? '' : " request_id={$response->request_id}",
            ));

            $location = $response->location();
            if (!in_array($response->status, [301, 302, 303, 307, 308], true) || $location === null) {
                return $response;
            }
            if (++$hops > self::MAX_REDIRECTS) {
                throw new UnexpectedResponseError('Too many redirects.', $response->status, null, $response, $key);
            }
            try {
                $target = UriResolver::resolve(new Uri($url), new Uri($location));
            } catch (\InvalidArgumentException) {
                // No URL can be made of this Location, and the parser's message would quote it.
                $target = null;
            }
            // Sent on, a URL without a host fails inside the client and is taken for a failure of the network. The next
            // attempt would get the same Location, so the redirect is the answer.
            if ($target === null || !in_array($target->getScheme(), ['http', 'https'], true) || $target->getHost() === '') {
                throw new UnexpectedResponseError('A redirect to a Location that is not an http or https URL was refused.', $response->status, null, $response, $key);
            }
            if (!self::sameOrigin($origin, $target) || !$this->underBasePath($target)) {
                $authorize = false;
            }
            if ($response->status === 303) {
                $method = 'GET';
                $body = null;
                unset($headers['Content-Type']);
            }
            $url = (string) $target;
        }
    }

    private static function connectionError(string $message, bool $mayPass, string $method, #[\SensitiveParameter] string $url, ?string $key): ConnectionError
    {
        $isTimeout = str_contains($message, 'cURL error 28') || stripos($message, 'timed out') !== false;
        $text = sprintf('%s %s failed: %s', $method, Redact::url($url), $message);
        // The client's exception is not chained: it holds the request, Authorization header included.
        return $isTimeout ? new TimeoutError($text, 0, null, null, $key) : new ConnectionError($text, 0, null, null, $key, null, $mayPass);
    }

    private static function sameOrigin(UriInterface $a, UriInterface $b): bool
    {
        return strtolower($a->getScheme()) === strtolower($b->getScheme())
            && strtolower($a->getHost()) === strtolower($b->getHost())
            && self::effectivePort($a) === self::effectivePort($b);
    }

    /** A gateway hosts other services beside the API, so outside the base URL's path the key stays behind too. */
    private function underBasePath(UriInterface $uri): bool
    {
        $prefix = rtrim((string) parse_url($this->config->baseUrl, PHP_URL_PATH), '/');
        $path = $uri->getPath();
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    private static function effectivePort(UriInterface $uri): int
    {
        return $uri->getPort() ?? (strtolower($uri->getScheme()) === 'https' ? 443 : 80);
    }

    /** @return array<string, string> */
    private static function flatHeaders(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }
        return $headers;
    }

    private function noteDeprecation(string $operationId, RawResponse $response): void
    {
        $deprecation = $response->header('deprecation');
        if ($deprecation === null || isset($this->warned[$operationId])) {
            return;
        }
        $this->warned[$operationId] = true;
        $info = ['deprecation' => $deprecation, 'sunset' => $response->header('sunset'), 'link' => $response->header('link')];
        $message = "Rask API operation $operationId is deprecated"
            . ($info['sunset'] === null ? '' : ", sunset {$info['sunset']}")
            . ($info['link'] === null ? '' : " ({$info['link']})") . '.';
        try {
            if ($this->config->onDeprecation !== null) {
                ($this->config->onDeprecation)($operationId, $info);
            } elseif ($this->config->logger !== null) {
                $this->config->logger->warning($message);
            } else {
                trigger_error($message, E_USER_DEPRECATED);
            }
        } catch (\Throwable) {
            // A strict error handler must not turn a call that succeeded on the server into a failure the caller may repeat.
        }
    }

    /**
     * @param array{idempotency: string, if_match: string, if_none_match: bool} $operation
     * @param array<array-key, mixed> $options
     */
    private function checkOptions(string $operationId, array $operation, array $options): void
    {
        foreach (array_keys($options) as $name) {
            if (!in_array($name, self::OPTION_NAMES, true)) {
                throw new UsageError("$operationId: unknown option '$name'. Known: " . implode(', ', self::OPTION_NAMES) . '.');
            }
        }
        if ($operation['if_match'] === 'required' && !isset($options['if_match'])) {
            throw new UsageError("$operationId requires if_match: pass the resource's current ETag in \$options['if_match'].");
        }
        if ($operation['if_match'] === 'none' && isset($options['if_match'])) {
            throw new UsageError("$operationId takes no if_match.");
        }
        if ($operation['idempotency'] === 'none' && isset($options['idempotency_key'])) {
            throw new UsageError("$operationId takes no idempotency_key.");
        }
        if (!$operation['if_none_match'] && isset($options['if_none_match'])) {
            throw new UsageError("$operationId takes no if_none_match.");
        }
    }

    /** @param array<array-key, mixed> $options */
    private static function stringOption(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new UsageError("Option '$name' must be a string.");
        }
        return $value;
    }

    /** @param array<string, string|int> $values */
    private static function fillPath(string $operationId, string $template, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $match) use ($operationId, $values): string {
                $value = (string) $values[$match[1]];
                // An empty id would turn DELETE /projects/{id} into another route; "." and ".." would walk the path.
                if ($value === '' || $value === '.' || $value === '..') {
                    throw new UsageError("$operationId: {$match[1]} cannot be \"$value\".");
                }
                return rawurlencode($value);
            },
            $template,
        );
    }

    /** @return array{base_url: string} */
    public function __debugInfo(): array
    {
        return ['base_url' => $this->config->baseUrl];
    }
}
