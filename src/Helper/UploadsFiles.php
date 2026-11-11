<?php

declare(strict_types=1);

namespace Rask\Sdk\Helper;

use Psr\Http\Message\StreamInterface;
use Rask\Sdk\Core\RawResponse;
use Rask\Sdk\Exception\ConnectionError;
use Rask\Sdk\Exception\UploadError;
use Rask\Sdk\Exception\UsageError;
use Rask\Sdk\Internal\Transport;
use Rask\Sdk\Model\Job;
use Rask\Sdk\Model\SingleUploadTransfer;
use Rask\Sdk\Model\Upload;

/** @internal Mixed into the generated Uploads resource. */
trait UploadsFiles
{
    /**
     * Uploads a file in one call: createUpload, one presigned PUT, completeUpload.
     *
     * The PUT goes to the URL the API signed, with the signed headers and without Authorization. It is retried whole, up
     * to 3 times, unless storage asks for a wait of over 120 s; a URL that expired is re-signed through getUpload.
     *
     * @param string|resource|StreamInterface $source a path, a stream resource or a PSR-7 stream. The stream stays open.
     * @param array{
     *     filename?: string,
     *     content_type?: string,
     *     purpose?: string,
     *     on_progress?: callable(int, int): void,
     * } $params filename and content_type are taken from a path, and a stream needs them. on_progress gets the bytes sent
     *     and the total, and starts again from 0 if the PUT is retried.
     * @return Job the media_import job: wait for it with $rask->jobs->wait($job->id), then read $job->media_id
     * @throws UploadError when the bytes did not reach storage; the upload is then not completed
     * @throws \Rask\Sdk\Exception\RaskError from createUpload, getUpload or completeUpload
     * @throws UsageError
     */
    public function uploadFile(mixed $source, array $params = []): Job
    {
        $unknown = array_diff(array_keys($params), ['filename', 'content_type', 'purpose', 'on_progress']);
        if ($unknown !== []) {
            throw new UsageError('uploadFile: unknown parameter ' . implode(', ', $unknown) . '.');
        }
        $onProgress = $params['on_progress'] ?? null;
        if ($onProgress !== null && !is_callable($onProgress)) {
            throw new UsageError('uploadFile: on_progress must be callable.');
        }
        $file = UploadSource::from($source, $params);
        try {
            $body = ['filename' => $file->filename, 'content_type' => $file->contentType, 'size_bytes' => $file->size];
            if (isset($params['purpose'])) {
                $body['purpose'] = $params['purpose'];
            }
            $upload = $this->createUpload($body);
            $progress = new ProgressStream($file->stream, $file->size, $onProgress === null ? null : \Closure::fromCallable($onProgress));
            $this->sendBytes($upload, $progress);
            $progress->report($file->size);
            return $this->completeUpload($this->uploadId($upload));
        } finally {
            $file->release();
        }
    }

    /** An upload holds its presigned URL, and an exception's trace keeps the arguments of every frame. */
    private function sendBytes(#[\SensitiveParameter] Upload $upload, ProgressStream $body): void
    {
        $failures = 0;
        $resigned = 0;
        $transfer = $this->transferOf($upload);
        // No point in sending gigabytes to a URL that is already dead.
        $expiresAt = strtotime((string) $transfer->expires_at);
        if ($expiresAt !== false && $expiresAt <= time()) {
            $upload = $this->getUpload($this->uploadId($upload));
            $transfer = $this->transferOf($upload);
            ++$resigned;
        }
        while (true) {
            $response = null;
            $failure = null;
            try {
                $response = $this->transport->putToStorage((string) $transfer->url, $transfer->headers ?? [], $body);
            } catch (ConnectionError $e) {
                $failure = $e;
            }
            if ($response !== null && $response->status >= 200 && $response->status < 300) {
                return;
            }
            if ($response !== null && self::urlExpired($response) && $resigned < 3) {
                $upload = $this->getUpload($this->uploadId($upload));
                $transfer = $this->transferOf($upload);
                ++$resigned;
                continue;
            }
            ++$failures;
            $transient = $response === null || in_array($response->status, [408, 429, 500, 502, 503, 504], true);
            $wait = max(Transport::backoff($failures), (float) ($response?->retryAfter() ?? 0));
            // Storage names its own wait and nothing else bounds it, so one past the budget of an API call ends the
            // retries here as it does there.
            if (!$transient || $failures > 3 || $wait > Transport::BUDGET_SECONDS) {
                throw new UploadError(
                    sprintf('The upload to storage failed after %d attempts: %s.', $failures, $response === null ? (string) $failure?->getMessage() : "HTTP {$response->status}"),
                    $response === null ? 0 : $response->status,
                    null,
                    $response,
                    null,
                    $failure,
                );
            }
            $this->transport->pause($wait);
        }
    }

    private function uploadId(#[\SensitiveParameter] Upload $upload): string
    {
        return $upload->id ?? throw new UploadError('The API returned an upload without an id.');
    }

    private function transferOf(#[\SensitiveParameter] Upload $upload): SingleUploadTransfer
    {
        $transfer = $upload->transfer;
        if (!$transfer instanceof SingleUploadTransfer || $transfer->url === null) {
            $method = $upload->get('/transfer/method');
            throw new UploadError('This SDK version cannot upload with the transfer method ' . (is_string($method) ? $method : '(none)') . '. Update the SDK.');
        }
        return $transfer;
    }

    private static function urlExpired(RawResponse $response): bool
    {
        return $response->status === 403 && stripos($response->body, 'expired') !== false;
    }
}
