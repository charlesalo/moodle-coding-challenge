<?php

declare(strict_types=1);

namespace App\Import;

use JsonSerializable;

/**
 * One reason a record was rejected, tied to the CSV line it came from.
 *
 * The line is the 1-based line number in the file, so it matches what a
 * spreadsheet shows rather than a zero-indexed record position.
 */
final class ValidationError implements JsonSerializable
{
    public function __construct(
        public readonly int $line,
        public readonly ?string $field,
        public readonly string $message,
    ) {
    }

    /** Human-readable form used by the CLI. */
    public function describe(): string
    {
        return $this->field !== null
            ? sprintf('Line %d [%s]: %s', $this->line, $this->field, $this->message)
            : sprintf('Line %d: %s', $this->line, $this->message);
    }

    /** @return array{line: int, field: string|null, message: string} */
    public function jsonSerialize(): array
    {
        return [
            'line'    => $this->line,
            'field'   => $this->field,
            'message' => $this->message,
        ];
    }
}
