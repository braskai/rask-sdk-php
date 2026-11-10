<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rask\Sdk\Internal\Operations;
use Rask\Sdk\Rask;

final class GeneratorTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, mixed> */
    private static function spec(): array
    {
        return json_decode((string) file_get_contents(self::root() . '/spec/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $spec */
    private static function generate(array $spec, string &$output = ''): array
    {
        $dir = sys_get_temp_dir() . '/' . uniqid('rask-gen-', true);
        mkdir($dir);
        file_put_contents("$dir/spec.json", json_encode($spec));
        exec(sprintf('%s %s --spec=%s --out=%s 2>&1', PHP_BINARY, escapeshellarg(self::root() . '/tools/generate.php'), escapeshellarg("$dir/spec.json"), escapeshellarg($dir)), $lines, $code);
        $output = implode("\n", $lines);
        return [$dir, $code];
    }

    public function testCommittedFilesMatchTheContract(): void
    {
        exec(sprintf('%s %s --check 2>&1', PHP_BINARY, escapeshellarg(self::root() . '/tools/generate.php')), $lines, $code);
        $this->assertSame(0, $code, implode("\n", $lines));
    }

    public function testTheContractCopyIsTheOneSourceJsonNames(): void
    {
        $source = json_decode((string) file_get_contents(self::root() . '/spec/SOURCE.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($source['sha256'], hash_file('sha256', self::root() . '/spec/openapi.json'));
    }

    public function testEveryGaOperationIsAMethodNamedAfterItsOperationId(): void
    {
        $spec = self::spec();
        $ids = [];
        foreach ($spec['paths'] as $item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (isset($item[$method])) {
                    $ids[] = $item[$method]['operationId'];
                }
            }
        }
        sort($ids);
        $this->assertCount(82, $ids);
        $known = array_keys(Operations::ALL);
        sort($known);
        $this->assertSame($ids, $known);

        $rask = new Rask(['api_key' => 'k']);
        foreach (Operations::ALL as $id => $meta) {
            $this->assertTrue(method_exists($rask->{$meta['resource']}, $id), "$id on {$meta['resource']}");
        }
    }

    /** S14: a deprecated operation, field and schema get the language's marker. */
    public function testDeprecatedMarkers(): void
    {
        $spec = self::spec();
        $spec['paths']['/me']['get']['deprecated'] = true;
        $spec['components']['schemas']['Job']['properties']['overdue']['deprecated'] = true;
        $spec['components']['schemas']['Problem']['deprecated'] = true;
        [$dir, $code] = self::generate($spec);
        $this->assertSame(0, $code);

        $account = (string) file_get_contents("$dir/src/Resource/Account.php");
        $this->assertMatchesRegularExpression('~@deprecated[^/]*\*/\s+public function getMe\(~s', $account);
        $this->assertDoesNotMatchRegularExpression('~@deprecated[^/]*\*/\s+public function getPlan\(~s', $account);
        $job = (string) file_get_contents("$dir/src/Model/Job.php");
        $this->assertMatchesRegularExpression('~@deprecated[^/]*\*/\s+public readonly \?bool \$overdue~s', $job);
        $this->assertStringContainsString('@deprecated', (string) file_get_contents("$dir/src/Model/Problem.php"));
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testAnUnknownSchemaFormStopsTheGenerator(): void
    {
        $spec = self::spec();
        $spec['components']['schemas']['Job']['properties']['surprise'] = ['anyOf' => [['type' => 'string'], ['type' => 'integer']]];
        $output = '';
        [$dir, $code] = self::generate($spec, $output);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('anyOf', $output);
        exec('rm -rf ' . escapeshellarg($dir));
    }

    public function testVersionMatchesComposer(): void
    {
        $composer = json_decode((string) file_get_contents(self::root() . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($composer['version'], \Rask\Sdk\Core\Version::VERSION);
    }
}
