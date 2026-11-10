<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rask\Sdk\Exception\UsageError;
use Rask\Sdk\Rask;
use Rask\Sdk\Tests\Acceptance\ScenarioRunner;

final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        ScenarioRunner::setEnvironment([]);
    }

    protected function tearDown(): void
    {
        ScenarioRunner::setEnvironment([]);
    }

    public function testDefaults(): void
    {
        $rask = new Rask(['api_key' => 'k']);
        $this->assertSame('https://api.rask.ai/v3', $rask->baseUrl());
        $this->assertSame(60, $rask->timeoutSeconds());
    }

    public function testMissingKeyNamesTheVariable(): void
    {
        $this->expectException(UsageError::class);
        $this->expectExceptionMessage('RASK_API_KEY');
        new Rask();
    }

    public function testAnEmptyKeyCountsAsMissing(): void
    {
        ScenarioRunner::setEnvironment(['RASK_API_KEY' => '']);
        $this->expectExceptionMessage('RASK_API_KEY');
        new Rask();
    }

    public function testAKeyReadWithItsNewlineIsTrimmed(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);
        $http->client(['api_key' => "raskai_test_key_unit\n"])->account->getMe();
        $this->assertSame('Bearer raskai_test_key_unit', $http->requests[0]->getHeaderLine('Authorization'));
    }

    #[DataProvider('badKeys')]
    public function testABadKeyFailsWithoutRepeatingIt(string $key): void
    {
        try {
            new Rask(['api_key' => $key]);
            $this->fail('The key was accepted.');
        } catch (UsageError $e) {
            $this->assertStringNotContainsString('topsecret', $e->getMessage());
            $this->assertStringContainsString('API key', $e->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function badKeys(): array
    {
        return [
            'newline' => ["raskai_\ntopsecret"],
            'carriage return' => ["raskai_\rtopsecret"],
            'space' => ['raskai_ topsecret'],
            'not ASCII' => ['raskai_ж_topsecret'],
        ];
    }

    /** Laravel and Symfony fill $_ENV or $_SERVER and may never call putenv(). */
    public function testEnvironmentFromEverySuperglobal(): void
    {
        $_SERVER['RASK_API_KEY'] = 'from-server';
        $_SERVER['RASK_BASE_URL'] = 'https://server.example/v3';
        $rask = new Rask();
        $this->assertSame('https://server.example/v3', $rask->baseUrl());

        $_ENV['RASK_BASE_URL'] = 'https://env.example/v3';
        $this->assertSame('https://env.example/v3', (new Rask())->baseUrl());
    }

    /** @return iterable<string, array{string, string}> */
    public static function baseUrls(): iterable
    {
        yield 'trailing slash' => ['https://gw.example.com/rask/v3/', 'https://gw.example.com/rask/v3'];
        yield 'several slashes' => ['https://gw.example.com/rask/v3///', 'https://gw.example.com/rask/v3'];
        yield 'no path' => ['https://gw.example.com', 'https://gw.example.com'];
        yield 'port' => ['http://127.0.0.1:8080/v3/', 'http://127.0.0.1:8080/v3'];
        yield 'spaces around' => ["  https://gw.example.com/v3\n", 'https://gw.example.com/v3'];
    }

    #[DataProvider('baseUrls')]
    public function testBaseUrlIsNormalized(string $given, string $expected): void
    {
        $this->assertSame($expected, (new Rask(['api_key' => 'k', 'base_url' => $given]))->baseUrl());
    }

    /** @return iterable<string, array{string}> */
    public static function badBaseUrls(): iterable
    {
        yield 'no scheme' => ['gw.example.com/v3'];
        yield 'ftp' => ['ftp://gw.example.com/v3'];
        yield 'query' => ['https://gw.example.com/v3?x=1'];
        yield 'fragment' => ['https://gw.example.com/v3#x'];
        yield 'credentials' => ['https://user:pass@gw.example.com/v3'];
    }

    #[DataProvider('badBaseUrls')]
    public function testBadBaseUrlIsRefused(string $url): void
    {
        $this->expectException(UsageError::class);
        new Rask(['api_key' => 'k', 'base_url' => $url]);
    }

    public function testATypoInAnOptionIsNotSilent(): void
    {
        $this->expectExceptionMessage('apiKey');
        new Rask(['apiKey' => 'k']); // @phpstan-ignore argument.type
    }

    public function testTimeoutMustBePositive(): void
    {
        $this->expectException(UsageError::class);
        new Rask(['api_key' => 'k', 'timeout_seconds' => 0]);
    }
}
