<?php

declare(strict_types=1);

namespace Rask\Sdk\Helper;

use Rask\Sdk\Enum\JobStatus;
use Rask\Sdk\Exception\ConnectionError;
use Rask\Sdk\Exception\JobCanceledError;
use Rask\Sdk\Exception\JobFailedError;
use Rask\Sdk\Exception\RaskError;
use Rask\Sdk\Exception\UsageError;
use Rask\Sdk\Exception\WaitTimeoutError;
use Rask\Sdk\Internal\Transport;
use Rask\Sdk\Model\Job;

/** @internal Mixed into the generated Jobs resource. */
trait WaitsForJobs
{
    /** 4 h: an on-screen job takes about 2.5 h. */
    public const DEFAULT_WAIT_TIMEOUT_SECONDS = 14400;

    /**
     * Waits until the job succeeds and returns it. A status this SDK version does not know counts as still running. The
     * job is left running if the timeout runs out.
     *
     * @throws JobFailedError carrying the job's Problem
     * @throws JobCanceledError
     * @throws WaitTimeoutError
     * @throws RaskError when the job cannot be read, as a 404 or a 401
     */
    public function wait(string $jobId, int|float $timeoutSeconds = self::DEFAULT_WAIT_TIMEOUT_SECONDS): Job
    {
        if ($timeoutSeconds <= 0) {
            throw new UsageError('$timeoutSeconds must be positive.');
        }
        $deadline = $this->transport->now() + $timeoutSeconds;
        $etag = null;
        $last = null;
        while (true) {
            // Long-poll for 20 s, except where that would hold the request past the deadline. Capped while it is a
            // float: the time left under a timeout such as INF does not convert to an int.
            $hold = (int) min(20, max(0, ceil($deadline - $this->transport->now())));
            $delay = 1.0;
            try {
                $job = $this->getJob($jobId, ['wait_seconds' => $hold], $etag === null ? [] : ['if_none_match' => $etag]);
                $response = $job->response();
                if (!$job->isNotModified()) {
                    $last = $job;
                    $etag = $response?->etag();
                    if ($job->status === JobStatus::SUCCEEDED) {
                        return $job;
                    }
                    if ($job->status === JobStatus::FAILED) {
                        $text = $job->error->detail ?? $job->error->title ?? "Job $jobId failed.";
                        throw new JobFailedError($job, $text, $response);
                    }
                    if ($job->status === JobStatus::CANCELED) {
                        throw new JobCanceledError($job, "Job $jobId was canceled.", $response);
                    }
                }
                $delay = (float) ($response?->retryAfter() ?? 1);
            } catch (RaskError $error) {
                // The job's own end is no outage, whatever status its problem carries: a finished job answers the same
                // on every poll.
                if ($error instanceof JobFailedError || $error instanceof JobCanceledError) {
                    throw $error;
                }
                // Transport spent its 120 s budget on an outage. A wait of hours outlives that.
                if (!$error instanceof ConnectionError && !in_array($error->status, Transport::RETRY_STATUSES, true)) {
                    throw $error;
                }
                if ($this->transport->now() >= $deadline) {
                    throw new WaitTimeoutError($jobId, $last, "Job $jobId did not finish in $timeoutSeconds s; the last read failed.", $error);
                }
                $delay = (float) ($error->retry_after ?? 5);
            }
            if ($this->transport->now() + $delay >= $deadline) {
                throw new WaitTimeoutError($jobId, $last, "Job $jobId did not finish in $timeoutSeconds s. It is still running.");
            }
            $this->transport->pause($delay);
        }
    }
}
