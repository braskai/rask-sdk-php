<?php

declare(strict_types=1);

namespace Rask\Sdk\Tests\Acceptance;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class CapturingLogger extends AbstractLogger
{
    /** @var list<string> the debug traffic log */
    public array $lines = [];

    /** @var list<string> deprecation warnings */
    public array $warnings = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if ($level === LogLevel::WARNING) {
            $this->warnings[] = (string) $message;
        } else {
            $this->lines[] = (string) $message;
        }
    }
}
