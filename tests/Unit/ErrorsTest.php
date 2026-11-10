<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rask\Sdk\Exception as E;

final class ErrorsTest extends TestCase
{
    /** @return iterable<string, array{int, class-string}> */
    public static function classes(): iterable
    {
        yield '400' => [400, E\BadRequestError::class];
        yield '401' => [401, E\AuthenticationError::class];
        yield '402' => [402, E\InsufficientBalanceError::class];
        yield '403' => [403, E\PermissionDeniedError::class];
        yield '404' => [404, E\NotFoundError::class];
        yield '409' => [409, E\ConflictError::class];
        yield '410' => [410, E\GoneError::class];
        yield '412' => [412, E\PreconditionFailedError::class];
        yield '413' => [413, E\ContentTooLargeError::class];
        yield '415' => [415, E\UnsupportedMediaTypeError::class];
        yield '422' => [422, E\ValidationError::class];
        yield '428' => [428, E\PreconditionRequiredError::class];
        yield '500' => [500, E\InternalServerError::class];
        yield '418' => [418, E\RaskError::class];
    }

    /** @param class-string $class */
    #[DataProvider('classes')]
    public function testClassFollowsTheStatusAlsoForAnHtmlBody(int $status, string $class): void
    {
        $http = new FakeHttpClient([new Response($status, ['Content-Type' => 'text/html', 'X-Request-Id' => 'r-1'], '<html>nope</html>')]);
        try {
            $http->client()->account->getMe();
            $this->fail('Must raise.');
        } catch (E\RaskError $e) {
            $this->assertInstanceOf($class, $e);
            $this->assertSame($status, $e->status);
            $this->assertNull($e->code);
            $this->assertFalse(isset($e->code));
            $this->assertSame($status, $e->getCode());
            $this->assertNull($e->problem);
            $this->assertSame('r-1', $e->request_id);
            $this->assertSame('<html>nope</html>', $e->response()?->body);
        }
    }

    public function testEveryMemberIsReadable(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(402, [
            'type' => 'https://docs.api.rask.ai/errors/insufficient_balance',
            'title' => 'Insufficient balance',
            'status' => 402,
            'detail' => 'Needs 5 minutes.',
            'instance' => 'urn:uuid:abc',
            'code' => 'insufficient_balance',
            'retryable' => false,
            'retry' => 'none',
            'recommendation' => 'top_up',
            'hint' => 'Buy minutes.',
            'errors' => [['pointer' => '/x', 'code' => 'c', 'detail' => 'd']],
            'credit_type' => 'minutes',
            'required_minutes' => 5,
            'balance_minutes' => 1,
            'brand_new_member' => 'kept',
        ])]);
        try {
            $http->client()->account->getMe();
            $this->fail('Must raise.');
        } catch (E\InsufficientBalanceError $e) {
            $this->assertSame('insufficient_balance', $e->code);
            $this->assertSame(402, $e->getCode(), 'getCode() stays an int: rethrowing code passes it to new Exception().');
            $this->assertTrue(isset($e->code));
            $this->assertSame('Insufficient balance', $e->title);
            $this->assertSame('Needs 5 minutes.', $e->detail);
            $this->assertSame('none', $e->retry);
            $this->assertFalse($e->retryable);
            $this->assertSame('Buy minutes.', $e->hint);
            $this->assertSame('/x', $e->errors[0]->pointer);
            $this->assertSame(1, $e->balance_minutes);
            $this->assertSame(5, $e->problem?->required_minutes);
            $this->assertSame('kept', $e->brand_new_member);
            $this->assertNull($e->not_there);
            $this->assertSame('abc', $e->request_id);
            $this->assertStringContainsString('Needs 5 minutes.', $e->getMessage());
        }
    }

    public function testUsageErrorsReachNoNetwork(): void
    {
        $http = new FakeHttpClient();
        $rask = $http->client();
        $calls = [
            'if_match missing' => static fn () => $rask->segments->deleteSegment('p', 's', []),
            'unknown option' => static fn () => $rask->account->getMe(['ifMatch' => 'x']),
            'if_match not taken' => static fn () => $rask->account->getMe(['if_match' => '"x"']),
            'key not taken' => static fn () => $rask->account->getMe(['idempotency_key' => 'x']),
            'if_none_match not taken' => static fn () => $rask->account->getMe(['if_none_match' => '"x"']),
            'bad type' => static fn () => $rask->projects->createFolder(['name' => 'x'], ['idempotency_key' => 5]),
        ];
        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("$name must raise.");
            } catch (E\UsageError) {
                $this->assertSame([], $http->requests, $name);
            }
        }
    }
}
