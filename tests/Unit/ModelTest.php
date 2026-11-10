<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rask\Sdk\Core\RawResponse;
use Rask\Sdk\Enum\JobStatus;
use Rask\Sdk\Exception\UnexpectedResponseError;
use Rask\Sdk\Model\Job;
use Rask\Sdk\Model\MinutesCost;
use Rask\Sdk\Model\Problem;
use Rask\Sdk\Model\UnknownVariant;

final class ModelTest extends TestCase
{
    public function testUnknownEnumValueIsKept(): void
    {
        $job = new Job(['status' => 's12_new', 'id' => 'j']);
        $this->assertSame('s12_new', $job->status);
        $this->assertNotContains($job->status, JobStatus::KNOWN);
    }

    public function testUnknownFieldIsKept(): void
    {
        $job = new Job(['id' => 'j', 'brand_new' => ['a' => 1]]);
        $this->assertSame(['brand_new' => ['a' => 1]], $job->additionalFields());
        $this->assertSame(['a' => 1], $job['brand_new']);
        $this->assertSame(1, $job->get('/brand_new/a'));
    }

    public function testKnownVariantIsTyped(): void
    {
        $job = new Job(['cost' => ['credit_type' => 'minutes', 'minutes' => 5]]);
        $this->assertInstanceOf(MinutesCost::class, $job->cost);
        $this->assertSame(['credit_type' => 'minutes', 'minutes' => 5], $job->cost->toArray());
    }

    public function testASecondValueOfADiscriminatorMapsToTheSameClass(): void
    {
        $job = new Job(['cost' => ['credit_type' => 'lipsync_free_minutes']]);
        $this->assertInstanceOf(MinutesCost::class, $job->cost);
    }

    public function testUnknownVariantIsKept(): void
    {
        $payload = ['credit_type' => 's12_unknown_variant', 'x' => 'y'];
        $job = new Job(['cost' => $payload]);
        $this->assertInstanceOf(UnknownVariant::class, $job->cost);
        $this->assertSame($payload, $job->cost->toArray());
        $this->assertSame('y', $job->cost['x']);
        $this->assertSame($payload, $job->get('/cost'));
    }

    public function testAVariantWithoutADiscriminatorIsUnknownNotFatal(): void
    {
        $job = new Job(['cost' => ['x' => 'y']]);
        $this->assertInstanceOf(UnknownVariant::class, $job->cost);
    }

    public function testValuesOfUnexpectedTypesAreNullInTypedPropertiesAndKeptInRaw(): void
    {
        $job = new Job(['progress' => 'soon', 'status' => 5, 'overdue' => 'no', 'cost' => 'free', 'stages' => 'many']);
        $this->assertNull($job->progress);
        $this->assertNull($job->status);
        $this->assertNull($job->overdue);
        $this->assertNull($job->cost);
        $this->assertNull($job->stages);
        $this->assertSame('soon', $job['progress']);
    }

    public function testMissingFieldsAreNull(): void
    {
        $job = new Job([]);
        $this->assertNull($job->id);
        $this->assertSame([], $job->toArray());
    }

    public function testModelsAreReadOnly(): void
    {
        $job = new Job(['id' => 'j']);
        $this->expectException(\LogicException::class);
        $job['id'] = 'k';
    }

    public function testSerializeRoundTrip(): void
    {
        $job = new Job(['id' => 'j', 'cost' => ['credit_type' => 'minutes'], 'brand_new' => 1], new RawResponse(200, [], '{}'));
        $copy = unserialize(serialize($job));
        $this->assertInstanceOf(Job::class, $copy);
        $this->assertSame('j', $copy->id);
        $this->assertInstanceOf(MinutesCost::class, $copy->cost);
        $this->assertSame(1, $copy['brand_new']);
    }

    public function testJsonEncodingIsTheOriginal(): void
    {
        $this->assertSame('{"id":"j","brand_new":1}', json_encode(new Job(['id' => 'j', 'brand_new' => 1])));
        $this->assertSame('{}', json_encode(new Job([])));
    }

    public function testPointerEscapes(): void
    {
        $job = new Job(['a/b' => ['c~d' => 1]]);
        $this->assertSame(1, $job->get('/a~1b/c~0d'));
        $this->assertSame('none', $job->get('/x/y', 'none'));
    }

    public function testHeadersAreCaseInsensitive(): void
    {
        $response = new RawResponse(200, ['etag' => '"a"', 'idempotent-replayed' => 'true', 'location' => '/v3/x', 'x-request-id' => 'r'], '');
        $this->assertSame('"a"', $response->header('ETag'));
        $this->assertSame('"a"', $response->etag());
        $this->assertSame('/v3/x', $response->location());
        $this->assertSame('r', $response->request_id);
        $this->assertTrue($response->idempotentReplayed());
    }

    public function testASuccessThatIsNotJsonRaisesATypedError(): void
    {
        $this->expectException(UnexpectedResponseError::class);
        (new RawResponse(200, [], '<html>a gateway page</html>'))->json();
    }

    public function testASuccessThatIsAJsonScalarRaisesATypedError(): void
    {
        $this->expectException(UnexpectedResponseError::class);
        (new RawResponse(200, [], '"ok"'))->json();
    }

    public function testIntegersFitOrStayStrings(): void
    {
        $this->assertSame(['n' => 5000000000], (new RawResponse(200, [], '{"n":5000000000}'))->json());
        $this->assertSame(['n' => '99999999999999999999'], (new RawResponse(200, [], '{"n":99999999999999999999}'))->json());
    }

    public function testProblemFieldErrorsAreTyped(): void
    {
        $problem = new Problem(['errors' => [['pointer' => '/x', 'code' => 'c', 'detail' => 'd']]]);
        $this->assertSame('/x', $problem->errors[0]->pointer);
    }
}
