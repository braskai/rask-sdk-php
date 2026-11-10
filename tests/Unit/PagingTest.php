<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rask\Sdk\Model\Project;

final class PagingTest extends TestCase
{
    /**
     * @param list<string> $ids
     * @return \GuzzleHttp\Psr7\Response
     */
    private static function page(array $ids, ?string $next): \GuzzleHttp\Psr7\Response
    {
        return FakeHttpClient::json(200, [
            'object' => 'list',
            'data' => array_map(static fn (string $id): array => ['id' => $id], $ids),
            'next_cursor' => $next,
            'has_more' => $next !== null,
        ]);
    }

    public function testForeachWalksEveryPageLazilyAndTypesItsItems(): void
    {
        $http = new FakeHttpClient([self::page(['a', 'b'], 'c2'), self::page([], 'c3'), self::page(['c'], null)]);
        $page = $http->client()->projects->listProjects(['limit' => 2, 'name_contains' => 'x']);
        $this->assertCount(1, $http->requests);
        $ids = [];
        foreach ($page as $project) {
            $this->assertInstanceOf(Project::class, $project);
            $ids[] = $project->id;
        }
        $this->assertSame(['a', 'b', 'c'], $ids);
        $this->assertCount(3, $http->requests);
        $this->assertSame('limit=2&name_contains=x', $http->requests[0]->getUri()->getQuery());
        $this->assertSame('cursor=c2&limit=2&name_contains=x', $http->requests[1]->getUri()->getQuery());
        $this->assertSame('cursor=c3&limit=2&name_contains=x', $http->requests[2]->getUri()->getQuery());
    }

    public function testIteratorToArrayKeepsEveryItem(): void
    {
        $http = new FakeHttpClient([self::page(['a'], 'c2'), self::page(['b'], null)]);
        $this->assertCount(2, iterator_to_array($http->client()->projects->listProjects()));
    }

    public function testPageLevelAccess(): void
    {
        $http = new FakeHttpClient([self::page(['a'], 'c2'), self::page(['b'], null)]);
        $page = $http->client()->projects->listProjects();
        $this->assertCount(1, $page->data);
        $this->assertTrue($page->has_more);
        $this->assertSame('c2', $page->next_cursor);
        $this->assertTrue($page->hasNextPage());
        $next = $page->nextPage();
        $this->assertNotNull($next);
        $this->assertFalse($next->hasNextPage());
        $this->assertNull($next->nextPage());
        $this->assertCount(2, $http->requests);
    }

    public function testAListInOnePageIteratesToo(): void
    {
        $http = new FakeHttpClient([self::page(['a', 'b'], null)]);
        $this->assertCount(2, iterator_to_array($http->client()->exports->listProjectOutputs('p')));
        $this->assertCount(1, $http->requests);
    }

    public function testPathParamsSurviveTheNextPage(): void
    {
        $http = new FakeHttpClient([self::page(['a'], 'c2'), self::page(['b'], null)]);
        iterator_to_array($http->client()->segments->listSegments('prj', ['limit' => 1]));
        $this->assertSame('/rask/v3/projects/prj/segments', $http->requests[1]->getUri()->getPath());
    }
}
