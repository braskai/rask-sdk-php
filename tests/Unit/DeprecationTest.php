<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rask\Sdk\Tests\Acceptance\CapturingLogger;

final class DeprecationTest extends TestCase
{
    private static function deprecated(): \GuzzleHttp\Psr7\Response
    {
        return FakeHttpClient::json(200, [], ['Deprecation' => 'true', 'Sunset' => 'Tue, 01 Jun 2027 00:00:00 GMT', 'Link' => '<https://docs>; rel="deprecation"']);
    }

    public function testByDefaultItIsAnEUserDeprecatedOncePerOperation(): void
    {
        $seen = [];
        set_error_handler(static function (int $level, string $message) use (&$seen): bool {
            $seen[] = [$level, $message];
            return true;
        });
        try {
            $http = new FakeHttpClient([self::deprecated(), self::deprecated(), self::deprecated()]);
            $rask = $http->client();
            $rask->account->getMe();
            $rask->account->getMe();
            $rask->account->getBalance();
        } finally {
            restore_error_handler();
        }
        $this->assertCount(2, $seen);
        $this->assertSame(E_USER_DEPRECATED, $seen[0][0]);
        $this->assertStringContainsString('getMe', $seen[0][1]);
        $this->assertStringContainsString('Tue, 01 Jun 2027', $seen[0][1]);
        $this->assertStringContainsString('getBalance', $seen[1][1]);
    }

    public function testACallbackReceivesTheHeaders(): void
    {
        $seen = [];
        $http = new FakeHttpClient([self::deprecated()]);
        $http->client(['on_deprecation' => static function (string $operation, array $info) use (&$seen): void {
            $seen[$operation] = $info;
        }])->account->getMe();
        $this->assertSame('true', $seen['getMe']['deprecation']);
        $this->assertStringContainsString('2027', (string) $seen['getMe']['sunset']);
    }

    public function testALoggerGetsAWarning(): void
    {
        $logger = new CapturingLogger();
        $http = new FakeHttpClient([self::deprecated()]);
        $http->client(['logger' => $logger])->account->getMe();
        $this->assertCount(1, $logger->warnings);
    }

    public function testAHandlerThatThrowsDoesNotLoseTheResult(): void
    {
        set_error_handler(static function (int $level, string $message): bool {
            throw new \ErrorException($message);
        });
        try {
            $http = new FakeHttpClient([FakeHttpClient::json(200, ['id' => 'me'], ['Deprecation' => 'true'])]);
            $this->assertSame('me', $http->client()->account->getMe()['id']);
        } finally {
            restore_error_handler();
        }
    }
}
