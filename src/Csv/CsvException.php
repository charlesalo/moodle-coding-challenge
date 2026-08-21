<?php

declare(strict_types=1);

namespace App\Csv;

use RuntimeException;

/**
 * A file-level problem that makes the CSV unusable: missing, unreadable,
 * empty, or lacking the required header.
 *
 * Problems with an individual row are not fatal; they are reported as
 * validation errors against that row instead.
 */
final class CsvException extends RuntimeException
{
}
