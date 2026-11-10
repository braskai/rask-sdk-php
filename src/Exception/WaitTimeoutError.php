<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

use Rask\Sdk\Model\Job;

/** jobs->wait(): the timeout ran out. The job keeps running on the server; wait again or cancel it. */
class WaitTimeoutError extends RaskError
{
    public function __construct(
        public readonly string $job_id,
        public readonly ?Job $last_job,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, null, null, null, $previous);
    }
}
