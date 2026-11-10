<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rask\Sdk\Internal\Encoder;
use Rask\Sdk\Model\Folder;

final class EncoderTest extends TestCase
{
    public function testAnEmptyMapStaysAnObject(): void
    {
        $this->assertSame('{"metadata":{}}', Encoder::body(['metadata' => []], 'UpdateProjectRequest'));
    }

    public function testAnEmptyBodyIsAnObject(): void
    {
        $this->assertSame('{}', Encoder::body([], 'CompleteUploadRequest'));
    }

    public function testEmptyStringsAreSentAndNullsAreNot(): void
    {
        $this->assertSame(
            '{"folder_id":"","metadata":{"crm_id":""}}',
            Encoder::body(['name' => null, 'folder_id' => '', 'metadata' => ['crm_id' => '', 'gone' => null]], 'UpdateProjectRequest'),
        );
    }

    public function testAListWithGapsStaysAList(): void
    {
        $body = ['segments' => array_filter([0 => null, 1 => ['id' => 'a', 'etag' => '"e"'], 3 => ['id' => 'b', 'etag' => '"f"']])];
        $this->assertSame(
            '{"segments":[{"id":"a","etag":"\"e\""},{"id":"b","etag":"\"f\""}]}',
            Encoder::body($body, 'MergeSegmentsRequest'),
        );
    }

    public function testAnEmptyListStaysAList(): void
    {
        $this->assertSame('{"segments":[]}', Encoder::body(['segments' => []], 'MergeSegmentsRequest'));
    }

    public function testAUnionPicksItsVariantByDiscriminator(): void
    {
        $encoded = Encoder::body(['source' => ['type' => 'media', 'media_id' => 'm'], 'name' => 'n'], 'CreateVoiceCloneRequest');
        $this->assertSame('{"source":{"type":"media","media_id":"m"},"name":"n"}', $encoded);
    }

    public function testAModelIsAccepted(): void
    {
        $this->assertSame('{"metadata":{"crm":"x"}}', Encoder::body(['metadata' => new Folder(['crm' => 'x'])], 'UpdateProjectRequest'));
    }

    public function testNumbersKeepTheirForm(): void
    {
        $this->assertSame(
            '{"start_seconds":1.0,"end_seconds":2.5}',
            Encoder::body(['start_seconds' => 1.0, 'end_seconds' => 2.5], 'CreateSegmentRequest'),
        );
    }

    public function testUnicodeAndSlashesAreNotEscaped(): void
    {
        $this->assertSame('{"name":"Привет/мир"}', Encoder::body(['name' => 'Привет/мир'], 'CreateFolderRequest'));
    }

    public function testQuery(): void
    {
        $this->assertSame('', Encoder::query([]));
        $this->assertSame('', Encoder::query(['a' => null]));
        $this->assertSame('?limit=5&q=a%20b&force=true&off=false', Encoder::query(['limit' => 5, 'q' => 'a b', 'force' => true, 'off' => false]));
        $this->assertSame('?ids=a,b%2Cc', Encoder::query(['ids' => ['a', 'b,c']]));
        $this->assertSame('?at=2026-11-03T09%3A00%3A00%2B00%3A00', Encoder::query(['at' => new \DateTimeImmutable('2026-11-03T09:00:00+00:00')]));
    }

    public function testBadQueryValueIsRefused(): void
    {
        $this->expectException(\Rask\Sdk\Exception\UsageError::class);
        Encoder::query(['a' => new \stdClass()]);
    }
}
