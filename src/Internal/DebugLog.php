<?php

declare(strict_types=1);

namespace Rask\Sdk\Internal;

use Psr\Log\LoggerInterface;

/**
 * Opt-in traffic log (RASK_LOG=debug or 'log' => 'debug'). It writes method, URL with signatures hidden, status and
 * timing. Never bodies, never the key: it is not handed either.
 *
 * @internal
 */
final class DebugLog
{
    public function __construct(private readonly bool $enabled, private readonly ?LoggerInterface $logger)
    {
    }

    public function write(string $line): void
    {
        if (!$this->enabled) {
            return;
        }
        if ($this->logger !== null) {
            $this->logger->debug($line);
            return;
        }
        $stream = fopen('php://stderr', 'w');
        if ($stream !== false) {
            fwrite($stream, "[rask] $line\n");
            fclose($stream);
        }
    }
}
