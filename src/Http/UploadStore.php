<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * Holds uploaded CSV files between the preview and import requests.
 *
 * The browser never sends parsed rows back. Preview stores the raw upload
 * under a server-generated token and returns only that token; import re-reads
 * the stored file and re-runs the same pipeline. That keeps validation on the
 * server and stops edited client data from reaching the database.
 */
final class UploadStore
{
    /** Tokens are 32 hex characters, so they can never escape the directory. */
    private const TOKEN_PATTERN = '/^[a-f0-9]{32}$/';

    private const MAX_AGE_SECONDS = 3600;

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * Store the contents of an uploaded file and return its token.
     *
     * @throws RuntimeException if the file cannot be stored
     */
    public function store(string $sourcePath, bool $isUpload = true): string
    {
        $this->ensureDirectory();

        // Guards against a path being passed off as an upload.
        if ($isUpload && !is_uploaded_file($sourcePath)) {
            throw new RuntimeException('The file was not received as an upload.');
        }

        $token = bin2hex(random_bytes(16));
        $target = $this->pathFor($token);

        $moved = $isUpload
            ? move_uploaded_file($sourcePath, $target)
            : copy($sourcePath, $target);

        if (!$moved) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        return $token;
    }

    /**
     * Resolve a client-supplied token to a stored file.
     *
     * @throws RuntimeException if the token is malformed or the file is gone
     */
    public function pathForToken(string $token): string
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw new RuntimeException('Invalid upload token.');
        }

        $path = $this->pathFor($token);
        if (!is_file($path)) {
            throw new RuntimeException(
                'That upload is no longer available. Please upload the file again.'
            );
        }

        return $path;
    }

    public function delete(string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return;
        }

        @unlink($this->pathFor($token));
    }

    /**
     * Remove stored uploads older than an hour, so nothing accumulates.
     */
    public function sweep(): void
    {
        foreach (glob($this->directory . '/*.csv') ?: [] as $file) {
            $modified = @filemtime($file);
            if ($modified !== false && (time() - $modified) > self::MAX_AGE_SECONDS) {
                @unlink($file);
            }
        }
    }

    /**
     * Built from the token pattern only, never by interpolating raw input.
     */
    private function pathFor(string $token): string
    {
        return $this->directory . '/' . $token . '.csv';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('Could not create the upload directory.');
        }

        if (!is_writable($this->directory)) {
            throw new RuntimeException('The upload directory is not writable: ' . $this->directory);
        }
    }
}
