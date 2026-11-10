<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Rask\Sdk\Internal\Operations;
use Rask\Sdk\Tests\Acceptance\ScenarioRunner;

/** The wire without a server. The same behaviour over real HTTP is in tests/Acceptance. */
final class BaseUrlTest extends TestCase
{
    public function testEveryOperationStaysUnderThePrefix(): void
    {
        $http = new FakeHttpClient();
        $rask = $http->client(['base_url' => 'https://gw.example.com/rask/v3/']);
        foreach (Operations::ALL as $id => $meta) {
            $http->queue = [FakeHttpClient::json(200, [])];
            $path = [];
            foreach ($meta['path_params'] as $name => $kind) {
                $path[$name] = $kind === 'int' ? 7 : 'id-1';
            }
            ScenarioRunner::callOperation($rask, $id, [
                'path' => $path,
                'if_match' => $meta['if_match'] === 'none' ? null : '"e"',
                'query' => $meta['has_query'] ? ['q' => 'x'] : [],
                'body' => [],
            ]);
            $request = end($http->requests);
            $expected = '/rask/v3' . preg_replace_callback('/\{(\w+)\}/', static fn (array $m): string => (string) $path[$m[1]], $meta['path']);
            $this->assertSame('gw.example.com', $request->getUri()->getHost(), $id);
            $this->assertSame($expected, $request->getUri()->getPath(), $id);
            $this->assertSame('Bearer raskai_test_key_unit', $request->getHeaderLine('Authorization'), $id);
        }
        $this->assertCount(82, Operations::ALL);
    }

    public function testRedirectToAnotherHostDropsTheKey(): void
    {
        $http = new FakeHttpClient([new Response(307, ['Location' => 'https://other.example.com/x']), FakeHttpClient::json(200, [])]);
        $http->client()->account->getMe();
        $this->assertSame('Bearer raskai_test_key_unit', $http->requests[0]->getHeaderLine('Authorization'));
        $this->assertFalse($http->requests[1]->hasHeader('Authorization'));
        $this->assertSame('https://other.example.com/x', (string) $http->requests[1]->getUri());
    }

    public function testRedirectToAnotherPortOrSchemeDropsTheKey(): void
    {
        foreach (['https://gw.example.com:8443/x', 'http://gw.example.com/x'] as $location) {
            $http = new FakeHttpClient([new Response(302, ['Location' => $location]), FakeHttpClient::json(200, [])]);
            $http->client()->account->getMe();
            $this->assertFalse($http->requests[1]->hasHeader('Authorization'), $location);
        }
    }

    public function testKeyStaysDroppedWhenARedirectComesBack(): void
    {
        $http = new FakeHttpClient([
            new Response(307, ['Location' => 'https://other.example.com/x']),
            new Response(307, ['Location' => 'https://gw.example.com/rask/v3/me']),
            FakeHttpClient::json(200, []),
        ]);
        $http->client()->account->getMe();
        $this->assertFalse($http->requests[2]->hasHeader('Authorization'));
    }

    public function testSameOriginRedirectKeepsTheKeyEvenWhenRelative(): void
    {
        $http = new FakeHttpClient([new Response(308, ['Location' => '/rask/v3/moved']), FakeHttpClient::json(200, [])]);
        $http->client()->account->getMe();
        $this->assertSame('Bearer raskai_test_key_unit', $http->requests[1]->getHeaderLine('Authorization'));
        $this->assertSame('https://gw.example.com/rask/v3/moved', (string) $http->requests[1]->getUri());
    }

    public function testRedirectOutsideThePrefixDropsTheKey(): void
    {
        foreach (['/other-service/x', '/rask/v3/../../other-service/x', 'https://gw.example.com/rask/v3-other/x', '/rask'] as $location) {
            $http = new FakeHttpClient([new Response(307, ['Location' => $location]), FakeHttpClient::json(200, [])]);
            $http->client()->account->getMe();
            $this->assertFalse($http->requests[1]->hasHeader('Authorization'), $location);
        }
    }

    public function testRedirectToAnotherSchemeIsRefused(): void
    {
        $http = new FakeHttpClient([new Response(302, ['Location' => 'file:///etc/passwd'])]);
        $this->expectException(\Rask\Sdk\Exception\UnexpectedResponseError::class);
        $http->client()->account->getMe();
    }

    public function testRedirectLoopEnds(): void
    {
        $loop = array_fill(0, 10, new Response(302, ['Location' => '/rask/v3/me']));
        $http = new FakeHttpClient($loop);
        try {
            $http->client()->account->getMe();
            $this->fail('A redirect loop must raise.');
        } catch (\Rask\Sdk\Exception\UnexpectedResponseError) {
            $this->assertCount(6, $http->requests);
        }
    }

    public function testA303DropsTheBody(): void
    {
        $http = new FakeHttpClient([new Response(303, ['Location' => '/rask/v3/folders/1']), FakeHttpClient::json(200, [])]);
        $http->client()->projects->createFolder(['name' => 'x']);
        $this->assertSame('GET', $http->requests[1]->getMethod());
        $this->assertSame('', $http->bodies[1]);
    }

    /** A presigned URL carries its signature; a 'location' in a job must never be resolved against the origin. */
    public function testAnEmptyIdNeverReachesAnotherRoute(): void
    {
        $http = new FakeHttpClient();
        foreach (['', '.', '..'] as $bad) {
            try {
                $http->client()->projects->deleteProject($bad);
                $this->fail('An id of "' . $bad . '" must be refused.');
            } catch (\Rask\Sdk\Exception\UsageError) {
                $this->assertSame([], $http->requests);
            }
        }
    }

    public function testIdsAreEncoded(): void
    {
        $http = new FakeHttpClient();
        $http->client()->projects->getProject('a/b?c');
        $this->assertSame('/rask/v3/projects/a%2Fb%3Fc', $http->requests[0]->getUri()->getPath());
    }
}
