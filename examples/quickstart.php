<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

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
            copy($output->url, 'dubbed.mp4');
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
