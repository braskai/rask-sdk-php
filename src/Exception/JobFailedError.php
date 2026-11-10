<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

use Rask\Sdk\Core\RawResponse;
use Rask\Sdk\Model\Job;

/** jobs->wait(): the job failed. Carries the job's Problem: code, detail, retry, recommendation. */
class JobFailedError extends RaskError
{
    public function __construct(public readonly Job $job, string $message, ?RawResponse $response = null)
    {
        parent::__construct($message, $job->error->status ?? 0, $job->error, $response);
    }
}
