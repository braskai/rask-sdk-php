<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Acceptance;

use Rask\Sdk\Core\Model;
use Rask\Sdk\Core\RawResponse;
use Rask\Sdk\Exception\RaskError;
use Rask\Sdk\Internal\Operations;
use Rask\Sdk\Rask;
use Rask\Sdk\Resource\Jobs;

/**
 * The loop every language writes (rask-sdk-acceptance/README.md): start a scenario, set the environment, build the client
 * from its args, run its calls through the public API, check expect_sdk, ask the harness to verify the wire.
 */
final class ScenarioRunner
{
    public const LANG = 'php';
    private const ENV = ['RASK_API_KEY', 'RASK_BASE_URL', 'RASK_LOG'];

    /** @var list<array<string, mixed>>|null */
    private ?array $operations = null;

    public function __construct(private readonly Harness $harness)
    {
    }

    /** @return array{verify: array<string, mixed>, sdk_failures: list<string>} */
    public function run(string $id): array
    {
        $scenario = $this->harness->post("/scenarios/$id/start", ['lang' => self::LANG]);
        self::setEnvironment($scenario['client']['env'] ?? []);

        $logger = new CapturingLogger();
        $run = ['client' => null, 'construct_error' => null, 'calls' => [], 'logger' => $logger];
        try {
            try {
                $run['client'] = new Rask(($scenario['client']['args'] ?? []) + ['logger' => $logger]);
            } catch (\Throwable $e) {
                $run['construct_error'] = $e;
            }
            if ($run['client'] !== null) {
                foreach ($scenario['calls'] ?? [] as $call) {
                    try {
                        $run['calls'][] = ['result' => $this->runCall($run['client'], $call, $run['calls'])];
                    } catch (\Throwable $e) {
                        $run['calls'][] = ['error' => $e];
                    }
                }
            }
        } finally {
            self::setEnvironment([]);
        }
        $run['warnings'] = $logger->warnings;

        $records = $this->harness->requests();
        $failures = [];
        foreach ($scenario['expect_sdk'] ?? [] as $expectation) {
            array_push($failures, ...$this->check($expectation, $run, $records));
        }
        $verify = $this->harness->post("/scenarios/$id/verify", [
            'lang' => self::LANG,
            'sdk_passed' => $failures === [],
            'sdk_detail' => implode('; ', $failures),
        ]);
        return ['verify' => $verify, 'sdk_failures' => $failures];
    }

    /** @param array<string, string> $env */
    public static function setEnvironment(array $env): void
    {
        foreach (self::ENV as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        foreach ($env as $name => $value) {
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }
    }

    /**
     * Calls an operation through its resource method, the way a user does.
     *
     * @param array<string, mixed> $call path, query, body, if_match, idempotency_key
     */
    public static function callOperation(Rask $rask, string $operationId, array $call): Model|RawResponse
    {
        $meta = Operations::ALL[$operationId];
        $args = [];
        foreach ($meta['path_params'] as $name => $kind) {
            $value = $call['path'][$name];
            $args[] = $kind === 'int' ? (int) $value : (string) $value;
        }
        if ($meta['body'] !== null) {
            $args[] = $call['body'] ?? [];
        }
        if ($meta['has_query']) {
            $args[] = $call['query'] ?? [];
        }
        $options = [];
        foreach (['if_match', 'idempotency_key'] as $name) {
            if (isset($call[$name])) {
                $options[$name] = $call[$name];
            }
        }
        $args[] = $options;
        return $rask->{$meta['resource']}->{$operationId}(...$args);
    }

    /**
     * @param array<string, mixed> $call
     * @param list<array<string, mixed>> $previous
     */
    private function runCall(Rask $rask, array $call, array $previous): mixed
    {
        if (isset($call['op']) && !isset($call['helper'])) {
            return self::callOperation($rask, $call['op'], $call);
        }
        switch ($call['helper']) {
            case 'jobs.wait':
                $jobId = $call['job_id'] ?? $previous[$call['job_id_from_call']]['result']->id;
                return isset($call['timeout_seconds']) ? $rask->jobs->wait($jobId, $call['timeout_seconds']) : $rask->jobs->wait($jobId);
            case 'uploads.upload_file':
                return $this->upload($rask, $call);
            case 'iterate':
                $items = [];
                foreach (self::callOperation($rask, $call['op'], $call) as $item) {
                    $items[] = $item;
                }
                return ['items' => $items];
            case 'first_page':
                return self::callOperation($rask, $call['op'], $call);
            case 'next_page':
                return $previous[$call['after_call']]['result']->nextPage();
            case 'sweep_operations':
                $results = [];
                foreach ($this->operations() as $operation) {
                    if ($operation['has_json_response']) {
                        $results[$operation['operationId']] = self::callOperation($rask, $operation['operationId'], self::sampleCall($operation));
                    }
                }
                return ['results' => $results];
        }
        throw new \LogicException("Unknown helper {$call['helper']}");
    }

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    public static function sampleCall(array $operation): array
    {
        $sample = $operation['sample'];
        return [
            'path' => $sample['path'],
            'query' => $sample['query'],
            'body' => $sample['body'],
            'if_match' => $sample['headers']['If-Match'] ?? null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function operations(): array
    {
        return $this->operations ??= $this->harness->get('/operations')['operations'];
    }

    /** @param array<string, mixed> $call */
    private function upload(Rask $rask, array $call): mixed
    {
        $bytes = '';
        for ($i = 0; $i < $call['content']['size_bytes']; ++$i) {
            $bytes .= chr($i % 256);
        }
        $progress = [];
        $params = [
            'filename' => $call['filename'],
            'content_type' => $call['content_type'],
            'on_progress' => static function (int $sent, int $total) use (&$progress): void {
                $progress[] = $sent;
            },
        ];
        if ($call['source'] === 'path') {
            $path = tempnam(sys_get_temp_dir(), 'rask-upload');
            file_put_contents($path, $bytes);
            try {
                $job = $rask->uploads->uploadFile($path, $params);
            } finally {
                unlink($path);
            }
        } else {
            $stream = fopen('php://temp', 'r+b');
            fwrite($stream, $bytes);
            rewind($stream);
            $job = $rask->uploads->uploadFile($stream, $params);
            if (!is_resource($stream)) {
                throw new \LogicException('uploadFile closed a stream it does not own.');
            }
            fclose($stream);
        }
        return ['job' => $job, 'progress' => $progress];
    }

    private static function bodyOf(mixed $result): mixed
    {
        if (is_array($result) && isset($result['job'])) {
            return $result['job']->toArray();
        }
        if ($result instanceof Model) {
            return $result->toArray();
        }
        return $result instanceof RawResponse ? $result->json() : null;
    }

    private static function rawOf(mixed $source): ?RawResponse
    {
        if (is_array($source) && isset($source['job'])) {
            return $source['job']->response();
        }
        if ($source instanceof Model || $source instanceof RaskError) {
            return $source->response();
        }
        return $source instanceof RawResponse ? $source : null;
    }

    private static function readPointer(mixed $value, string $pointer): mixed
    {
        if ($pointer === '' || $pointer === '/') {
            return $value;
        }
        foreach (explode('/', ltrim($pointer, '/')) as $part) {
            $part = str_replace(['~1', '~0'], ['/', '~'], $part);
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    private static function text(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $expectation
     * @param array<string, mixed> $run
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    private function check(array $expectation, array $run, array $records): array
    {
        $calls = $run['calls'];
        $outcome = $calls[$expectation['call'] ?? count($calls) - 1] ?? null;
        $error = $outcome['error'] ?? null;
        $result = $outcome['result'] ?? null;
        $client = $run['client'];

        switch ($expectation['kind']) {
            case 'returned':
                return $outcome !== null && $error === null ? [] : ['call raised: ' . ($error?->getMessage() ?? 'no call')];
            case 'returned_or_raised':
            case 'manual':
                return [];
            case 'raised':
                return $this->checkRaised($expectation, $error, $records);
            case 'raised_before_network':
                $raised = $run['construct_error'] ?? ($calls[0]['error'] ?? null);
                if ($raised === null) {
                    return ['expected an error before any request'];
                }
                return str_contains($raised->getMessage(), $expectation['message_contains'])
                    ? [] : ["error \"{$raised->getMessage()}\" does not mention {$expectation['message_contains']}"];
            case 'config':
                if ($client === null) {
                    return ['no client: ' . $run['construct_error']?->getMessage()];
                }
                $actual = match ($expectation['field']) {
                    'base_url' => $client->baseUrl(),
                    'timeout_seconds' => $client->timeoutSeconds(),
                    'jobs.wait.timeout_seconds' => Jobs::DEFAULT_WAIT_TIMEOUT_SECONDS,
                };
                return $actual === $expectation['equals'] ? [] : ["config {$expectation['field']} is " . self::text($actual)];
            case 'repr_redacts':
                return $this->checkRedacted($client, $expectation['secret']);
            case 'field':
                if ($error !== null || $outcome === null) {
                    return ['call raised: ' . $error?->getMessage()];
                }
                if (isset($expectation['raw_status']) && self::rawOf($result)?->status !== $expectation['raw_status']) {
                    return ['status is ' . self::rawOf($result)?->status];
                }
                if (!isset($expectation['path'])) {
                    return [];
                }
                $value = self::readPointer(self::bodyOf($result), $expectation['path']);
                if ($expectation['present'] ?? false) {
                    return $value === null ? ["{$expectation['path']} is missing"] : [];
                }
                return $value === $expectation['equals'] ? [] : ["{$expectation['path']} is " . self::text($value)];
            case 'header_exposed':
                return $this->checkHeader($expectation, $result ?? $error, $records);
            case 'idempotency_key_exposed':
                $key = $error instanceof RaskError ? $error->idempotency_key : self::rawOf($result)?->idempotency_key;
                if ($key === null) {
                    return ['the key is not exposed'];
                }
                if (isset($expectation['equals']) && $key !== $expectation['equals']) {
                    return ["key is $key"];
                }
                if (($expectation['matches_wire'] ?? false) && !array_filter($records, static fn (array $r): bool => ($r['headers']['idempotency-key'] ?? null) === $key)) {
                    return ["key $key was never sent"];
                }
                return [];
            case 'items':
                $items = $result['items'] ?? null;
                if ($items === null) {
                    return ['no items: ' . $error?->getMessage()];
                }
                if (isset($expectation['count']) && count($items) !== $expectation['count']) {
                    return ['saw ' . count($items) . " items, expected {$expectation['count']}"];
                }
                if (isset($expectation['ids'])) {
                    $ids = array_map(static fn (object $item): mixed => $item->id ?? null, $items);
                    if ($ids !== $expectation['ids']) {
                        return ['saw ids ' . self::text($ids)];
                    }
                }
                return [];
            case 'pages':
                if (!$result instanceof Model) {
                    return ['no page: ' . $error?->getMessage()];
                }
                if (count($result->data) !== $expectation['item_count']) {
                    return ['page has ' . count($result->data) . ' items'];
                }
                return $result->has_more === $expectation['has_more'] ? [] : ['has_more is ' . self::text($result->has_more)];
            case 'warnings':
                return $this->checkWarnings($expectation, $run['warnings']);
            case 'progress':
                $progress = $result['progress'] ?? [];
                return end($progress) === $expectation['final_bytes'] ? [] : ['on_progress did not reach the file size: ' . self::text($progress)];
            case 'logs':
                return $this->checkLogs($expectation, $run['logger']->lines, $records);
            case 'injected_values_preserved':
                return $this->checkInjected($result, $records);
        }
        throw new \LogicException("Unknown expectation {$expectation['kind']}");
    }

    /**
     * @param array<string, mixed> $expectation
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    private function checkRaised(array $expectation, ?\Throwable $error, array $records): array
    {
        if ($error === null) {
            return ['expected an error, the call returned'];
        }
        $failures = [];
        if (isset($expectation['error_class']) && !is_a($error, 'Rask\\Sdk\\Exception\\' . $expectation['error_class'])) {
            $failures[] = "expected {$expectation['error_class']}, got " . $error::class . ': ' . $error->getMessage();
        }
        if (!$error instanceof RaskError) {
            if (isset($expectation['status']) || isset($expectation['code'])) {
                $failures[] = 'expected a RaskError, got ' . $error::class . ': ' . $error->getMessage();
            }
            return $failures;
        }
        if (isset($expectation['status']) && $error->status !== $expectation['status']) {
            $failures[] = "expected status {$expectation['status']}, got {$error->status}";
        }
        if (isset($expectation['code']) && $error->code !== $expectation['code']) {
            $failures[] = "expected code {$expectation['code']}, got " . self::text($error->code);
        }
        $problem = $error->problem?->toArray() ?? [];
        foreach ($expectation['fields'] ?? [] as $name => $value) {
            $actual = $name === 'retry_after' ? $error->retry_after : ($problem[$name] ?? null);
            if ($actual !== $value) {
                $failures[] = "expected $name " . self::text($value) . ', got ' . self::text($actual);
            }
            // The same member through the typed property or the magic one: what a user reads.
            if ($name !== 'errors' && $name !== 'retry_after' && $error->{$name} !== $value) {
                $failures[] = "\$e->$name is " . self::text($error->{$name});
            }
        }
        foreach ($expectation['present'] ?? [] as $name) {
            if (!array_key_exists($name, $problem)) {
                $failures[] = "expected $name on the error";
            }
        }
        if ($expectation['request_id_matches_wire'] ?? false) {
            $known = array_filter($records, static fn (array $r): bool => ($r['response']['headers']['X-Request-Id'] ?? null) === $error->request_id);
            if ($known === []) {
                $failures[] = "request_id {$error->request_id} is not an X-Request-Id the server sent";
            }
        }
        return $failures;
    }

    /** @return list<string> */
    private function checkRedacted(?Rask $client, string $secret): array
    {
        if ($client === null) {
            return ['no client'];
        }
        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();
        $texts = [(string) $client, $dump, print_r($client, true), var_export($client, true), (string) json_encode($client)];
        $texts[] = var_export((array) $client, true);
        try {
            $texts[] = serialize($client);
        } catch (\LogicException) {
            // Refusing is fine.
        }
        foreach ($texts as $text) {
            if (str_contains($text, $secret)) {
                return ['the key shows in a dump of the client'];
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $expectation
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    private function checkHeader(array $expectation, mixed $source, array $records): array
    {
        $name = $expectation['name'];
        $value = self::rawOf($source)?->header($name);
        if ($value === null) {
            return ["header $name is not exposed"];
        }
        if (isset($expectation['equals']) && $value !== $expectation['equals']) {
            return ["header $name: expected {$expectation['equals']}, got $value"];
        }
        if (isset($expectation['matches']) && !preg_match('~' . str_replace('~', '\\~', $expectation['matches']) . '~', $value)) {
            return ["header $name: $value does not match {$expectation['matches']}"];
        }
        if (isset($expectation['equals_field']) && $value !== self::readPointer(self::bodyOf($source), $expectation['equals_field'])) {
            return ["header $name differs from {$expectation['equals_field']}"];
        }
        if (($expectation['matches_wire'] ?? false) && !array_filter($records, static fn (array $r): bool => ($r['response']['headers'][$name] ?? null) === $value)) {
            return ["header $name $value is not what the server sent"];
        }
        return [];
    }

    /**
     * @param array<string, mixed> $expectation
     * @param list<string> $warnings
     * @return list<string>
     */
    private function checkWarnings(array $expectation, array $warnings): array
    {
        $failures = count($warnings) === $expectation['count'] ? [] : ['saw ' . count($warnings) . " warnings, expected {$expectation['count']}: " . self::text($warnings)];
        foreach ($expectation['per_operation'] ?? [] as $operation => $count) {
            $seen = count(array_filter($warnings, static fn (string $w): bool => str_contains($w, "operation $operation is deprecated")));
            if ($seen !== $count) {
                $failures[] = "$operation: saw $seen warnings, expected $count";
            }
        }
        return $failures;
    }

    /**
     * @param array<string, mixed> $expectation
     * @param list<string> $lines
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    private function checkLogs(array $expectation, array $lines, array $records): array
    {
        $logs = implode("\n", $lines);
        $failures = [];
        if (($expectation['empty'] ?? false) && $logs !== '') {
            $failures[] = 'expected no logs, got ' . substr($logs, 0, 200);
        }
        foreach ($expectation['must_contain'] ?? [] as $text) {
            if (!str_contains($logs, $text)) {
                $failures[] = "logs do not contain $text";
            }
        }
        foreach ($expectation['must_not_contain'] ?? [] as $text) {
            if (str_contains($logs, $text)) {
                $failures[] = 'logs contain a secret';
            }
        }
        foreach ($expectation['must_not_contain_wire'] ?? [] as $wire) {
            foreach ($records as $record) {
                if ($record['operationId'] === $wire['operationId'] && str_contains($logs, (string) $record['raw_query_params'][$wire['query']])) {
                    $failures[] = "logs contain {$wire['query']}";
                }
            }
        }
        return $failures;
    }

    /**
     * Reads each injected value back through the public accessor, Model::get().
     *
     * @param list<array<string, mixed>> $records
     * @return list<string>
     */
    private function checkInjected(mixed $result, array $records): array
    {
        $failures = [];
        $seen = false;
        $results = is_array($result) ? ($result['results'] ?? null) : null;
        foreach ($records as $record) {
            $injections = $record['response']['injections'] ?? [];
            if ($injections === []) {
                continue;
            }
            $seen = true;
            $model = $results === null ? $result : ($results[$record['operationId']] ?? null);
            if (!$model instanceof Model) {
                $failures[] = "{$record['operationId']} returned no model";
                continue;
            }
            foreach ($injections as $injection) {
                if ($model->get($injection['pointer']) !== $injection['value']) {
                    $failures[] = "{$record['operationId']} lost the {$injection['kind']} injected at {$injection['pointer']}";
                }
            }
            TypedIntegrity::assertMatchesRaw($model, $record['operationId'], $failures);
        }
        if (!$seen) {
            $failures[] = 'nothing was injected';
        }
        return $failures;
    }
}
