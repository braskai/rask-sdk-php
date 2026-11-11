# Rask SDK for PHP

The Rask API v3 from PHP 8.2 or newer: all 82 operations, grouped by resource and named after their `operationId`, plus
retries, idempotency keys, auto-paging, `jobs->wait()` and `uploads->uploadFile()`.

Version 3.0.0-alpha.0, on [Packagist](https://packagist.org/packages/braskai/rask-sdk).

## Install

```sh
composer require braskai/rask-sdk:3.0.0-alpha.0
```

## Base URL

```php
use Rask\Sdk\Rask;

$rask = new Rask([
    'api_key' => $key,                                  // or the RASK_API_KEY environment variable
    'base_url' => 'https://gw.example.com/rask/v3',     // or RASK_BASE_URL; default https://api.rask.ai/v3
]);
```

Every operation and helper uses `base_url`. A path prefix is kept and a trailing slash is fine. The key goes only to URLs
under it: a presigned upload PUT carries no `Authorization`, and a redirect anywhere else (another scheme, host or port, or
out of the path prefix) drops it.

## Worked example

Upload, create a project, wait, download. This is [`examples/quickstart.php`](examples/quickstart.php), and a test runs it.

```php
use Rask\Sdk\Enum\OutputKind;
use Rask\Sdk\Exception\InsufficientBalanceError;
use Rask\Sdk\Exception\JobFailedError;
use Rask\Sdk\Model\ProjectProcessingJobResult;
use Rask\Sdk\Rask;

// The key comes from RASK_API_KEY, the base URL from RASK_BASE_URL. Or pass them: new Rask(['api_key' => $key, 'base_url' => $url]).
$rask = new Rask();

try {
    // Upload. The bytes go straight to storage, without your key. The import job makes them a media item.
    $import = $rask->uploads->uploadFile($argv[1] ?? 'talk.mp4');
    $media = $rask->jobs->wait((string) $import->id);

    // Create the project: transcription, translation, then an automatic dub within this many minutes.
    $created = $rask->projects->createProject([
        'media_id' => (string) $media->media_id,
        'target_language' => 'es-es',
        'expected_cost_minutes' => 10,
    ]);
    $processed = $rask->jobs->wait((string) $created->id);

    // The automatic dub is a job of its own.
    $result = $processed->result;
    if ($result instanceof ProjectProcessingJobResult && $result->auto_dub?->dub_job_id !== null) {
        $rask->jobs->wait($result->auto_dub->dub_job_id);
    }

    // Download. Output URLs are presigned: no key goes with them.
    foreach ($rask->exports->listProjectOutputs((string) $processed->project_id) as $output) {
        if ($output->kind === OutputKind::DUBBED_VIDEO && $output->url !== null) {
            // copy() answers false when storage refuses, as it does once the URL has expired.
            if (!copy($output->url, 'dubbed.mp4')) {
                echo "The download failed.\n";
                exit(1);
            }
            echo "Saved dubbed.mp4\n";
        }
    }
} catch (InsufficientBalanceError $e) {
    echo "Top up first: {$e->balance_minutes} minutes left, {$e->required_minutes} needed.\n";
    exit(1);
} catch (JobFailedError $e) {
    echo "Job {$e->job->id} failed: {$e->code}: {$e->detail} (request {$e->request_id})\n";
    exit(1);
}
```

## Calling the API

A method takes the path parameters, then `$body` and `$query` as arrays if the operation has them, then `$options`.

```php
$rask->segments->updateSegment($projectId, $segmentId, ['translation_text' => 'Hola'], ['if_match' => $segment->etag]);
$rask->projects->updateProject($projectId, ['folder_id' => '']);          // "" clears a field; null is never sent

foreach ($rask->projects->listProjects(['limit' => 50]) as $project) {}   // every page
$page = $rask->projects->listProjects(['limit' => 50]);                   // or by hand: $page->data, $page->nextPage()

$folder = $rask->projects->createFolder(['name' => 'Q3']);
$folder->response()->status;                                              // 201, and header('Location'), request_id, ...
$folder->idempotencyKey();                                                // the key it sent, or pass your own: ['idempotency_key' => ...]
```

| Option | |
|---|---|
| `api_key`, `base_url` | Above |
| `timeout_seconds` | Per API request, default 60 |
| `upload_timeout_seconds` | Per presigned PUT, default 3600 |
| `http_client` | A PSR-18 client, used for everything, and it then owns the timeouts. Default: Guzzle |
| `log` | `'debug'` logs traffic with secrets hidden. Default: the `RASK_LOG` environment variable |
| `logger` | PSR-3 logger for that log and for deprecation warnings. Default: stderr and `E_USER_DEPRECATED` |
| `on_deprecation` | `callable(string $operationId, array $headers)`, once per operation |

**Results** are typed objects whose properties have the API's own names. Every property is nullable. A new field, enum value
or union variant does not break you: enum properties are plain strings (compare with `Enum\JobStatus::SUCCEEDED`), an unknown
variant is a `Model\UnknownVariant`, and `toArray()`, `get('/a/b')` and `$model['a']` read everything the server sent.

**Errors** extend `Exception\RaskError`, with `status`, `code` (a string, also for codes this version does not know), `type`,
`title`, `detail`, `request_id`, `retryable`, `retry`, `hint`, `errors`, `retry_after`, `idempotency_key`, `problem`,
`response()` and any other Problem member by name. Subclasses go by status: `InsufficientBalanceError`, `NotFoundError`,
`PreconditionFailedError`, `RateLimitError` and so on, plus `ConnectionError`, `TimeoutError`, `UploadError`, `JobFailedError`,
`JobCanceledError` and `WaitTimeoutError`. A call that was wrong before it left raises `UsageError`.

**Retries:** 429, 502, 503, 504, connection errors and timeouts, up to 120 s in all, honouring `Retry-After`. A wait that
cannot fit in the 120 s ends the retries at once. 500 and state conflicts are not retried. Neither is a certificate that
fails verification: the call raises `ConnectionError` at once, and so does `jobs->wait()`. The SDK knows it by cURL's
error code, so that takes Guzzle on the cURL extension: without cURL Guzzle reports such a certificate as a refused
connection, and that is retried like any other, as is whatever another `http_client` reports.

## Develop

Everything runs in Docker (`make help` reads the top of the [Makefile](Makefile)):

```sh
make install
make lint        # composer validate, php -l, generated files match the contract, PHPStan at max
make test        # PHPUnit on PHP 8.2, against the shared harness in ../rask-sdk-acceptance
make check       # lint, then the tests on PHP 8.2 and 8.5
```

Against any harness or a test stand, without Docker:

```sh
RASK_ACCEPTANCE_CONTROL_URL=http://127.0.0.1:4010/__control vendor/bin/phpunit
RASK_API_KEY=... RASK_BASE_URL=https://.../v3 php examples/smoke.php
```

### How it is generated

`spec/openapi.json` is a copy of the contract (`spec/SOURCE.json` says of what). `tools/generate.php`, about 1,000 lines with no
dependencies, writes `src/Model`, `src/Enum`, `src/Resource` and the tables in `src/Internal`, which are committed. CI fails if
they differ from the contract, and the generator stops on a schema form it does not know. Everything else in `src` is written
by hand.

Measured on 2026-11-05 on this contract without overlays, decoding a `Job` from the contract's own example:

| Generator | Unknown enum value | Unknown union variant | Known variant |
|---|---|---|---|
| OpenAPI Generator 7.27 `php` | `InvalidArgumentException` | `InvalidArgumentException` | `InvalidArgumentException` on `credit_type: minutes`, the contract's own example |
| Kiota 1.38 `PHP` | `InvalidArgumentException` | `InvalidArgumentException` | Decodes into one class with every variant's fields |
| `tools/generate.php` | Kept | `UnknownVariant`, kept | Its own class |

The fallback overlays could turn open enums into strings for those two, but not keep unknown variants (the
same holds for Kiota in Java and Python), and S12 asks for that on every response. So a thin typed layer over the JSON costs less than
patching their output.

## License

MIT. See [LICENSE](LICENSE).
