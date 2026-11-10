<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Acceptance;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Every scenario of rask-sdk-acceptance over real HTTP, on a base URL with a path prefix. */
#[Group('acceptance')]
final class ScenariosTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function scenarios(): iterable
    {
        $runSlow = getenv('RUN_SLOW') === '1';
        foreach ((new Harness())->get('/scenarios?lang=' . ScenarioRunner::LANG)['scenarios'] as $scenario) {
            if ($scenario['applies'] && ($runSlow || !$scenario['slow'])) {
                yield $scenario['id'] => [$scenario['id']];
            }
        }
    }

    #[DataProvider('scenarios')]
    public function testScenario(string $id): void
    {
        $outcome = (new ScenarioRunner(new Harness()))->run($id);
        $this->assertSame([], $outcome['sdk_failures'], 'What the SDK returned or raised');
        $this->assertTrue($outcome['verify']['pass'], 'Wire: ' . json_encode($outcome['verify']['failures'], JSON_PRETTY_PRINT));
    }

    /** Runs after the scenarios, in file order: the harness refuses a language that skipped or failed one. */
    public function testReportIsComplete(): void
    {
        $slow = getenv('RUN_SLOW') === '1' ? '&slow=true' : '';
        $report = (new Harness())->get('/report?lang=' . ScenarioRunner::LANG . $slow);
        $this->assertSame([], $report['failed'], 'failed');
        $this->assertSame([], $report['not_run'], 'not run');
        $this->assertTrue($report['complete'], json_encode($report));
    }
}
