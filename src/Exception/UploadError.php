<?php

declare(strict_types=1);

namespace Rask\Sdk\Exception;

/** The bytes of uploads->uploadFile() did not reach storage. status is storage's, 0 if no response arrived. */
class UploadError extends RaskError
{
}
