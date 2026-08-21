<?php

declare(strict_types=1);

/**
 * POST a CSV file, get back a validation report and an upload token.
 *
 * Runs the shared ImportService in dry-run mode, so nothing is written. The
 * token identifies the stored file for a later call to import.php; the parsed
 * rows are deliberately not trusted back from the client.
 */

use App\Csv\CsvException;
use App\Db\DatabaseException;
use App\Http\Bootstrap;
use App\Http\Json;

require __DIR__ . '/../../vendor/autoload.php';

Bootstrap::guardAgainstUncaughtErrors();
Json::requirePost();

$uploads = Bootstrap::uploads();

// Clear out abandoned uploads on every request so nothing accumulates.
$uploads->sweep();

$temporaryPath = Json::requireCsvUpload();

// Report problems against the name the user recognises, never the server path
// the upload was stored at.
$originalName = basename((string) ($_FILES['file']['name'] ?? 'the uploaded file'));

try {
    $token = $uploads->store($temporaryPath);
} catch (RuntimeException $e) {
    Json::error($e->getMessage(), 500);
}

// A preview is still useful without a database, so an outage degrades the
// conflict check rather than failing the request.
try {
    $repository = Bootstrap::repository();
} catch (DatabaseException) {
    $repository = null;
}

try {
    $report = Bootstrap::importService($repository)
        ->run($uploads->pathForToken($token), dryRun: true, label: $originalName);
} catch (CsvException $e) {
    // The file is unusable, so there is nothing to import later.
    $uploads->delete($token);
    Json::error($e->getMessage(), 422);
}

Json::send([
    'token'  => $token,
    'report' => $report,
]);
