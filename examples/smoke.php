<?php

declare(strict_types=1);

/*
 * Touches the main read paths and one create, get and delete, and prints one line per call with its status. Prints no
 * key and no signed URL. Reads RASK_API_KEY and RASK_BASE_URL, and refuses to run without RASK_BASE_URL, because it
 * writes a folder and the default base URL is production.
 *
 *     RASK_API_KEY=... RASK_BASE_URL=https://.../v3 php examples/smoke.php
 *
 * Exit code 0 when every call succeeded, 1 otherwise. It carries on after a failure, and deletes the folder it made.
 */

require __DIR__ . '/../vendor/autoload.php';

use Rask\Sdk\Core\Model;
use Rask\Sdk\Core\RawResponse;
use Rask\Sdk\Exception\RaskError;
use Rask\Sdk\Exception\UsageError;
use Rask\Sdk\Model\Folder;
use Rask\Sdk\Model\ProjectList;
use Rask\Sdk\Rask;

if ((getenv('RASK_BASE_URL') ?: '') === '') {
    fwrite(STDERR, "Set RASK_BASE_URL: this program writes, and the default base URL is production.\n");
    exit(2);
}

try {
    $rask = new Rask();
} catch (UsageError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$failed = 0;

/** @param callable(): (Model|RawResponse) $call */
function step(string $name, callable $call, int &$failed): Model|RawResponse|null
{
    $started = hrtime(true);
    try {
        $result = $call();
        $response = $result instanceof RawResponse ? $result : $result->response();
        printf("%-22s status=%d request_id=%s %d ms\n", $name, (int) $response?->status, (string) $response?->request_id, (hrtime(true) - $started) / 1_000_000);
        return $result;
    } catch (RaskError $e) {
        ++$failed;
        printf("%-22s status=%d code=%s request_id=%s %d ms FAILED\n", $name, $e->status, $e->code ?? $e::class, (string) $e->request_id, (hrtime(true) - $started) / 1_000_000);
        return null;
    }
}

echo 'base_url=', $rask->baseUrl(), "\n";

step('getMe', static fn () => $rask->account->getMe(), $failed);
step('listLanguages', static fn () => $rask->catalogs->listLanguages(), $failed);
step('listVoices', static fn () => $rask->voices->listVoices(['target_language' => 'ru-ru']), $failed);

$first = step('listProjects', static fn () => $rask->projects->listProjects(['limit' => 2]), $failed);
if ($first instanceof ProjectList) {
    if ($first->hasNextPage()) {
        step('listProjects page 2', static function () use ($first): Model {
            return $first->nextPage() ?? throw new \LogicException('The first page said there was a next one.');
        }, $failed);
    } else {
        echo "listProjects page 2    skipped: the first page is the last\n";
    }
}

step('getBalance', static fn () => $rask->account->getBalance(), $failed);
step('getPlan', static fn () => $rask->account->getPlan(), $failed);

$folder = step('createFolder', static fn () => $rask->projects->createFolder(['name' => 'rask-sdk-php smoke ' . date('c')]), $failed);
if ($folder instanceof Folder && $folder->id !== null) {
    $id = $folder->id;
    step('getFolder', static fn () => $rask->projects->getFolder($id), $failed);
    step('deleteFolder', static fn () => $rask->projects->deleteFolder($id), $failed);
}

exit($failed === 0 ? 0 : 1);
