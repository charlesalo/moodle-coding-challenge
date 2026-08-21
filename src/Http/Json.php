<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Small helpers for the two JSON endpoints.
 */
final class Json
{
    /** Uploads are capped well below a CSV of any sane size. */
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    public static function send(mixed $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function error(string $message, int $status = 400): never
    {
        self::send(['error' => $message], $status);
        exit;
    }

    public static function requirePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            self::error('This endpoint only accepts POST requests.', 405);
        }
    }

    /**
     * Validate an uploaded CSV and return its temporary path.
     *
     * Checks the PHP upload status, the size cap and the .csv extension
     * before anything touches the filesystem.
     */
    public static function requireCsvUpload(string $field = 'file'): string
    {
        $upload = $_FILES[$field] ?? null;

        if (!is_array($upload) || !isset($upload['error'])) {
            self::error('No file was uploaded. Choose a CSV file and try again.');
        }

        match ($upload['error']) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_NO_FILE => self::error('No file was uploaded. Choose a CSV file and try again.'),
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => self::error('That file is too large to upload.', 413),
            UPLOAD_ERR_PARTIAL => self::error('The upload did not complete. Please try again.'),
            default => self::error('The file could not be uploaded. Please try again.', 500),
        };

        if (($upload['size'] ?? 0) > self::MAX_UPLOAD_BYTES) {
            self::error(sprintf(
                'That file is larger than the %d MB limit.',
                (int) (self::MAX_UPLOAD_BYTES / 1024 / 1024),
            ), 413);
        }

        $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            self::error('Only .csv files can be imported.', 415);
        }

        return (string) $upload['tmp_name'];
    }
}
