<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rask\Sdk\Internal\Operations;
use Rask\Sdk\Internal\Uuid;

final class IdempotencyAndIfMatchTest extends TestCase
{
    private const UUID4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testUuidsAreVersion4(): void
    {
        for ($i = 0; $i < 100; ++$i) {
            $this->assertMatchesRegularExpression(self::UUID4, Uuid::v4());
        }
    }

    /** S4: POST, PATCH and PUT carry a key, and deleteSegment; GET and other DELETEs do not. */
    public function testWhichOperationsCarryAKey(): void
    {
        foreach (Operations::ALL as $id => $meta) {
            $expected = in_array($meta['method'], ['POST', 'PATCH', 'PUT'], true) || $id === 'deleteSegment';
            $this->assertSame($expected, $meta['idempotency'] !== 'none', $id);
        }
    }

    public function testIfMatchIsSentVerbatim(): void
    {
        $http = new FakeHttpClient();
        $http->client()->segments->updateSegment('p', 's', ['translation_text' => 'x'], ['if_match' => 'W/"a"']);
        $this->assertSame('W/"a"', $http->requests[0]->getHeaderLine('If-Match'));
    }

    public function testMergeTakesItsETagsInTheBodyOnly(): void
    {
        $this->assertSame('none', Operations::ALL['mergeSegments']['if_match']);
        $this->assertSame('none', Operations::ALL['createSegment']['if_match']);
    }

    public function testAClientIdentifiesItself(): void
    {
        $http = new FakeHttpClient();
        $http->client()->account->getMe();
        $this->assertMatchesRegularExpression('~^rask-sdk-php/3\.0\.0-alpha\.0 php/\S+$~', $http->requests[0]->getHeaderLine('User-Agent'));
        $this->assertSame('rask-sdk-php/3.0.0-alpha.0', $http->requests[0]->getHeaderLine('X-Rask-Client'));
    }
}
