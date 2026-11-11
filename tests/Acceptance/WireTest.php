<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Acceptance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Rask\Sdk\Core\Model;
use Rask\Sdk\Core\RawResponse;
use Rask\Sdk\Exception\RaskError;
use Rask\Sdk\Internal\Operations;
use Rask\Sdk\Rask;

/** What the scenario list does not reach, over real HTTP: every operation by name, big files, redirects, the examples. */
#[Group('acceptance')]
final class WireTest extends TestCase
{
    private Harness $harness;

    /** @var array<string, mixed> */
    private array $health;

    protected function setUp(): void
    {
        $this->harness = new Harness();
        $this->health = $this->harness->health();
        $this->harness->post('/reset');
        ScenarioRunner::setEnvironment([]);
    }

    protected function tearDown(): void
    {
        ScenarioRunner::setEnvironment([]);
        $this->harness->post('/reset');
    }

    /** @param array<string, mixed> $options */
    private function rask(array $options = []): Rask
    {
        return new Rask($options + ['api_key' => $this->health['test_api_key'], 'base_url' => $this->health['base_url']]);
    }

    /** @return iterable<string, array{string}> */
    public static function baseUrlForms(): iterable
    {
        yield 'as printed' => [''];
        yield 'with a trailing slash' => ['/'];
    }

    /** Every one of the 82 operations by its resource method, with the contract's sample, under the custom prefix. */
    #[DataProvider('baseUrlForms')]
    public function testEveryOperationIsCallable(string $suffix): void
    {
        $operations = $this->harness->get('/operations')['operations'];
        $this->assertCount(82, $operations);
        $rask = $this->rask(['base_url' => $this->health['base_url'] . $suffix]);
        foreach ($operations as $operation) {
            $id = $operation['operationId'];
            $this->harness->post('/reset');
            $result = ScenarioRunner::callOperation($rask, $id, ScenarioRunner::sampleCall($operation));
            $raw = $result instanceof Model ? $result->response() : $result;
            $this->assertInstanceOf(RawResponse::class, $raw, $id);
            $this->assertSame($operation['success_status'], $raw->status, $id);

            $requests = $this->harness->requests();
            $this->assertCount(1, $requests, $id);
            $this->assertSame($id, $requests[0]['operationId'], $id);
            $this->assertSame([], $requests[0]['violations'], $id);
            $this->assertStringStartsWith($this->health['prefix'] . '/', $requests[0]['path'], $id);
            $this->assertSame('Bearer ' . $this->health['test_api_key'], $requests[0]['headers']['authorization'], $id);
            $this->assertMatchesRegularExpression('~^rask-sdk-php/~', $requests[0]['headers']['x-rask-client'], $id);
            $this->assertSame($operation['idempotency_key'] === 'none', !isset($requests[0]['headers']['idempotency-key']), $id);
        }
    }

    /** The hardened Encoder must not break a sample: every sample body round-trips to the same JSON. */
    public function testSampleBodiesAreSentUnchanged(): void
    {
        foreach ($this->harness->get('/operations')['operations'] as $operation) {
            // An optional body that is empty is not sent at all (estimateDub).
            if (in_array($operation['sample']['body'], [null, []], true)) {
                continue;
            }
            $this->harness->post('/reset');
            ScenarioRunner::callOperation($this->rask(), $operation['operationId'], ScenarioRunner::sampleCall($operation));
            $this->assertSame($operation['sample']['body'], $this->harness->requests()[0]['body'], $operation['operationId']);
        }
    }

    public function testHelpersFollowRaskBaseUrlWithATrailingSlash(): void
    {
        ScenarioRunner::setEnvironment([
            'RASK_API_KEY' => $this->health['test_api_key'],
            'RASK_BASE_URL' => $this->health['base_url'] . '/',
        ]);
        $this->harness->post('/pages', ['operationId' => 'listProjects', 'pages' => 2, 'page_size' => 1]);
        $this->harness->post('/jobs', ['id' => 'job-env', 'type' => 'dub', 'states' => ['succeeded']]);

        $rask = new Rask();
        $this->assertCount(2, iterator_to_array($rask->projects->listProjects()));
        $this->assertSame('succeeded', $rask->jobs->wait('job-env')->status);
        $path = tempnam(sys_get_temp_dir(), 'rask');
        file_put_contents($path, str_repeat('x', 64));
        try {
            $rask->uploads->uploadFile($path, ['filename' => 'a.mp4', 'content_type' => 'video/mp4']);
        } finally {
            unlink($path);
        }
        $requests = $this->harness->requests();
        $this->assertGreaterThan(5, count($requests));
        foreach ($requests as $request) {
            $this->assertSame([], $request['violations']);
            if ($request['operationId'] === '__storage_put') {
                $this->assertArrayNotHasKey('authorization', $request['headers']);
                $this->assertSame('other', $request['listener']);
            } else {
                $this->assertSame('api', $request['listener']);
                $this->assertStringStartsWith($this->health['prefix'] . '/', $request['path']);
                $this->assertArrayHasKey('authorization', $request['headers']);
            }
        }
    }

    /** Over 1 MB Guzzle streams through a read callback and adds Expect: 100-Continue: another path than a small body. */
    public function testALargeFileFromAPathAndFromAPipeArrivesWhole(): void
    {
        $size = 3 * 1024 * 1024;
        $path = tempnam(sys_get_temp_dir(), 'rask');
        $handle = fopen($path, 'wb');
        for ($i = 0; $i < 3 * 1024; ++$i) {
            fwrite($handle, str_repeat(chr($i % 256), 1024));
        }
        fclose($handle);
        $sha = hash_file('sha256', $path);

        try {
            $progress = [];
            $this->rask()->uploads->uploadFile($path, [
                'filename' => 'big.mp4',
                'content_type' => 'video/mp4',
                'on_progress' => static function (int $sent, int $total) use (&$progress, $size): void {
                    self::assertSame($size, $total);
                    $progress[] = $sent;
                },
            ]);
            $this->assertSame($size, end($progress));
            $this->assertGreaterThan(2, count($progress), 'A big file reports as it goes.');
            $this->assertSame($progress, array_values(array_unique($progress)));

            $put = $this->lastPut();
            $this->assertSame(200, $put['response']['status']);
            $this->assertSame($sha, $put['body_sha256']);
            $this->assertSame((string) $size, $put['headers']['content-length']);
            $this->assertArrayNotHasKey('authorization', $put['headers']);

            $pipe = popen('cat ' . escapeshellarg($path), 'r');
            $this->harness->post('/reset');
            $this->rask()->uploads->uploadFile($pipe, ['filename' => 'pipe.mp4', 'content_type' => 'video/mp4']);
            pclose($pipe);
            $put = $this->lastPut();
            $this->assertSame(200, $put['response']['status']);
            $this->assertSame($sha, $put['body_sha256']);
            $this->assertArrayNotHasKey('transfer-encoding', $put['headers']);
        } finally {
            unlink($path);
        }
    }

    /** @return array<string, mixed> */
    private function lastPut(): array
    {
        $puts = array_values(array_filter($this->harness->requests(), static fn (array $r): bool => $r['operationId'] === '__storage_put'));
        $this->assertNotEmpty($puts);
        return end($puts);
    }

    public function testASameOriginRedirectKeepsTheKeyAndAnotherPortLosesIt(): void
    {
        $base = $this->health['base_url'];
        $this->harness->post('/script', ['operationId' => 'getMe', 'steps' => [['redirect' => ['to' => "$base/balance", 'status' => 307]]]]);
        $this->rask()->account->getMe();
        $requests = $this->harness->requests();
        $this->assertSame('getBalance', $requests[1]['operationId']);
        $this->assertSame('Bearer ' . $this->health['test_api_key'], $requests[1]['headers']['authorization']);

        $this->harness->post('/reset');
        $otherPort = (int) parse_url($this->health['other_base_url'], PHP_URL_PORT);
        $apiHost = (string) parse_url($base, PHP_URL_HOST);
        $this->harness->post('/script', ['operationId' => 'getMe', 'steps' => [['redirect' => ['to' => "http://$apiHost:$otherPort/x", 'status' => 307]]]]);
        try {
            $this->rask()->account->getMe();
        } catch (RaskError) {
            // Following or refusing both pass; the key is what counts.
        }
        $other = array_values(array_filter($this->harness->requests(), static fn (array $r): bool => $r['listener'] === 'other'));
        $this->assertCount(1, $other);
        $this->assertArrayNotHasKey('authorization', $other[0]['headers']);
    }

    /** The real default client: Guzzle refuses a Location without a host before it reaches the network. */
    public function testARedirectGuzzleRefusesToRequestIsRefusedWithoutARetry(): void
    {
        $this->harness->post('/script', ['operationId' => 'getMe', 'steps' => [['redirect' => ['to' => 'http:/x', 'status' => 307], 'repeat' => 'forever']]]);
        $now = 0.0;
        $sleeps = 0;
        $rask = $this->rask([
            'clock' => static function () use (&$now): float {
                return $now;
            },
            'sleeper' => static function (int|float $seconds) use (&$now, &$sleeps): void {
                $now += $seconds;
                ++$sleeps;
            },
        ]);
        try {
            $rask->account->getMe();
            $this->fail('Must raise.');
        } catch (RaskError $e) {
            $this->assertInstanceOf(\Rask\Sdk\Exception\UnexpectedResponseError::class, $e);
            $this->assertSame(307, $e->status);
        }
        $this->assertSame(0, $sleeps);
        $this->assertCount(1, $this->harness->requests());
    }

    /** The real default client and its timeout: the harness holds the answer longer than timeout_seconds. */
    public function testATimeoutIsRaisedAsTimeoutErrorOnTheDefaultClient(): void
    {
        $this->harness->post('/script', ['operationId' => 'getMe', 'steps' => [['delay_ms' => 1500, 'repeat' => 'forever']]]);
        $now = 0.0;
        $rask = $this->rask([
            'timeout_seconds' => 0.2,
            'clock' => static function () use (&$now): float {
                return $now;
            },
            'sleeper' => static function (int|float $seconds) use (&$now): void {
                $now += 200;
            },
        ]);
        $this->expectException(\Rask\Sdk\Exception\TimeoutError::class);
        $rask->account->getMe();
    }

    /** @param list<string> $arguments */
    private function php(string $script, array $env, array $arguments = [], ?string $cwd = null): array
    {
        $command = [PHP_BINARY, dirname(__DIR__, 2) . "/examples/$script", ...$arguments];
        $environment = $env + ['PATH' => (string) getenv('PATH')];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $environment);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    public function testSmokeProgram(): void
    {
        $this->harness->post('/pages', ['operationId' => 'listProjects', 'pages' => 2, 'page_size' => 2]);
        [$code, $stdout, $stderr] = $this->php('smoke.php', [
            'RASK_API_KEY' => $this->health['test_api_key'],
            'RASK_BASE_URL' => $this->health['base_url'],
            'RASK_LOG' => 'debug',
        ]);
        $this->assertSame(0, $code, $stdout . $stderr);
        foreach (['getMe', 'listLanguages', 'listVoices', 'listProjects', 'listProjects page 2', 'getBalance', 'getPlan', 'createFolder', 'getFolder', 'deleteFolder'] as $name) {
            $this->assertMatchesRegularExpression('/^' . preg_quote($name, '/') . '\s+status=\d{3} /m', $stdout, $name);
        }
        $this->assertStringContainsString('status=204', $stdout);
        // RASK_LOG=debug goes to stderr by default, and neither stream holds the key.
        $this->assertStringContainsString('> GET ' . $this->health['base_url'] . '/me', $stderr);
        $this->assertStringNotContainsString($this->health['test_api_key'], $stdout . $stderr);

        $paths = array_column($this->harness->requests(), 'path');
        $this->assertContains($this->health['prefix'] . '/voices', $paths);
        $this->assertSame([], array_merge(...array_column($this->harness->requests(), 'violations')));
    }

    public function testSmokeProgramRefusesToRunWithoutABaseUrl(): void
    {
        [$code, , $stderr] = $this->php('smoke.php', ['RASK_API_KEY' => $this->health['test_api_key']]);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('RASK_BASE_URL', $stderr);
    }

    public function testSmokeProgramReportsAFailureAndCarriesOn(): void
    {
        $this->harness->post('/script', ['operationId' => 'getBalance', 'steps' => [['problem' => 'insufficient_scope', 'repeat' => 'forever']]]);
        [$code, $stdout] = $this->php('smoke.php', [
            'RASK_API_KEY' => $this->health['test_api_key'],
            'RASK_BASE_URL' => $this->health['base_url'],
        ]);
        $this->assertSame(1, $code);
        $this->assertMatchesRegularExpression('/^getBalance\s+status=403 code=insufficient_scope .*FAILED$/m', $stdout);
        $this->assertMatchesRegularExpression('/^deleteFolder\s+status=204/m', $stdout);
    }

    /**
     * Runs examples/quickstart.php in a directory of its own, against a project whose dubbed video is at $url.
     *
     * @return array{int, string, string, bool} exit code, stdout, stderr, and whether dubbed.mp4 is there
     */
    private function quickstart(string $url): array
    {
        $this->harness->post('/jobs', ['id' => 'job-import', 'type' => 'media_import', 'states' => ['running', 'succeeded'], 'retry_after' => 1]);
        $this->harness->post('/jobs', ['id' => 'job-processing', 'type' => 'project_processing', 'states' => ['succeeded']]);
        $this->harness->post('/script', ['operationId' => 'completeUpload', 'steps' => [['job' => 'job-import']]]);
        $this->harness->post('/script', ['operationId' => 'createProject', 'steps' => [['job' => 'job-processing']]]);
        $sample = $this->harness->get('/operations?operationId=listProjectOutputs')['operations'][0]['sample'] ?? null;
        $this->assertNotNull($sample);
        $this->harness->post('/script', ['operationId' => 'listProjectOutputs', 'steps' => [[
            'body' => [
                'object' => 'list',
                'has_more' => false,
                'next_cursor' => null,
                'data' => [[
                    'object' => 'output',
                    'kind' => 'dubbed_video',
                    'format' => 'mp4',
                    'language' => 'es-es',
                    'available' => true,
                    'unavailable_reason' => null,
                    'outdated' => false,
                    'version' => null,
                    'url' => $url,
                    'expires_at' => '2030-01-01T00:00:00Z',
                    'subtitles_request' => null,
                    'filename' => 'dubbed.mp4',
                ]],
            ],
            'off_contract' => true,
        ]]]);

        $dir = sys_get_temp_dir() . '/' . uniqid('rask-qs-', true);
        mkdir($dir);
        file_put_contents("$dir/talk.mp4", str_repeat('v', 2048));
        try {
            [$code, $stdout, $stderr] = $this->php('quickstart.php', [
                'RASK_API_KEY' => $this->health['test_api_key'],
                'RASK_BASE_URL' => $this->health['base_url'],
            ], ["$dir/talk.mp4"], $dir);
            return [$code, $stdout, $stderr, file_exists("$dir/dubbed.mp4")];
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    /** The README's worked example, run: upload, createProject, jobs.wait, download. */
    public function testQuickstartProgramAndItsCopyInTheReadme(): void
    {
        [$code, $stdout, $stderr, $saved] = $this->quickstart($this->health['other_base_url'] . '/downloads/dubbed.mp4?X-Amz-Signature=abc');
        $this->assertSame(0, $code, $stdout . $stderr);
        $this->assertStringContainsString('Saved dubbed.mp4', $stdout);
        $this->assertTrue($saved);

        $requests = $this->harness->requests();
        $order = array_column(array_filter($requests, static fn (array $r): bool => $r['listener'] === 'api' || $r['operationId'] === '__storage_put'), 'operationId');
        $this->assertSame(
            // Import twice (running, succeeded), processing once, then the auto-dub job its result names.
            ['createUpload', '__storage_put', 'completeUpload', 'getJob', 'getJob', 'createProject', 'getJob', 'getJob', 'listProjectOutputs'],
            $order,
        );
        foreach ($requests as $request) {
            $this->assertSame([], $request['violations'], (string) $request['operationId']);
            if ($request['listener'] === 'other') {
                $this->assertArrayNotHasKey('authorization', $request['headers']);
            }
        }
        $this->assertNotEmpty(array_filter($requests, static fn (array $r): bool => str_contains((string) $r['path'], '/downloads/dubbed.mp4')));

        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $body = (string) file_get_contents(dirname(__DIR__, 2) . '/examples/quickstart.php');
        $this->assertStringContainsString($this->quickstartBody($body), $readme, 'The README copy of examples/quickstart.php has drifted.');
    }

    /** An output URL lives for an hour. The API answers 401 here, as storage answers 403 once the URL has expired. */
    public function testQuickstartProgramDoesNotSaySavedWhenTheDownloadFailed(): void
    {
        [$code, $stdout, , $saved] = $this->quickstart($this->health['base_url'] . '/me');
        $this->assertFalse($saved);
        $this->assertStringNotContainsString('Saved', $stdout);
        $this->assertSame(1, $code, $stdout);
    }

    private function quickstartBody(string $source): string
    {
        return trim(substr($source, (int) strpos($source, 'use Rask\\Sdk\\Enum\\OutputKind;')));
    }

    /** A scripted 200 that is an HTML page, as a wrong base_url behind a gateway gives: a typed error, not a TypeError. */
    public function testASuccessThatIsNotJsonIsATypedError(): void
    {
        $this->harness->post('/script', ['operationId' => 'getMe', 'steps' => [['status' => 200, 'raw' => '<html>login</html>', 'content_type' => 'text/html', 'off_contract' => true]]]);
        $this->expectException(\Rask\Sdk\Exception\UnexpectedResponseError::class);
        $this->expectExceptionMessage('base_url');
        $this->rask()->account->getMe();
    }

    public function testOperationTableAgreesWithTheHarness(): void
    {
        foreach ($this->harness->get('/operations')['operations'] as $operation) {
            $meta = Operations::ALL[$operation['operationId']];
            $this->assertSame($operation['method'], $meta['method'], $operation['operationId']);
            $this->assertSame($operation['path'], $meta['path'], $operation['operationId']);
            $this->assertSame($operation['idempotency_key'], $meta['idempotency'], $operation['operationId']);
            $this->assertSame($operation['if_match'], $meta['if_match'], $operation['operationId']);
            $this->assertSame($operation['pagination'] === 'cursor', $meta['paginated'], $operation['operationId']);
            $this->assertSame($operation['success_status'], $meta['status'], $operation['operationId']);
        }
    }
}
