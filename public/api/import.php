<?php

declare(strict_types=1);

/**
 * POST an upload token, get back the result of importing that stored file.
 *
 * Re-reads the file saved by preview.php and re-runs the same ImportService
 * for real. Nothing about the records comes from the request body, so a user
 * cannot edit rows in the browser and have them inserted.
 */

use App\Csv\CsvException;
use App\Db\DatabaseException;
use App\Http\Bootstrap;
use App\Http\Json;

require __DIR__ . '/../../vendor/autoload.php';

Bootstrap::guardAgainstUncaughtErrors();
Json::requirePost();

$uploads = Bootstrap::uploads();
$uploads->sweep();

$token = (string) ($_POST['token'] ?? '');
if ($token === '') {
    $body  = json_decode((string) file_get_contents('php://input'), true);
    $token = is_array($body) ? (string) ($body['token'] ?? '') : '';
}

if ($token === '') {
    Json::error('An upload token is required. Preview the file before importing.');
}

try {
    $path = $uploads->pathForToken($token);
} catch (RuntimeException $e) {
    Json::error($e->getMessage(), 404);
}

try {
    $repository = Bootstrap::repository();
} catch (DatabaseException $e) {
    Json::error($e->getMessage(), 503);
}

try {
    $report = Bootstrap::importService($repository)
        ->run($path, dryRun: false, label: 'the uploaded file');
} catch (CsvException $e) {
    Json::error($e->getMessage(), 422);
}

if ($report->hasFailed()) {
    // Keep the file so the user can retry once the database is back.
    Json::send(['report' => $report, 'error' => $report->failure], 500);
    exit;
}

// The import succeeded, so the stored upload is no longer needed.
$uploads->delete($token);

Json::send(['report' => $report]);
