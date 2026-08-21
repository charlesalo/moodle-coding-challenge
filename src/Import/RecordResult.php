<?php

declare(strict_types=1);

namespace App\Import;

use JsonSerializable;

/**
 * The outcome of validating one CSV row: the normalised record, whether it
 * can be imported, and every reason it cannot.
 */
final class RecordResult implements JsonSerializable
{
    /** @param list<ValidationError> $errors */
    public function __construct(
        public readonly int $line,
        public readonly UserRecord $record,
        public readonly array $errors = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** Return a copy with one more error attached. */
    public function withError(ValidationError $error): self
    {
        return new self($this->line, $this->record, [...$this->errors, $error]);
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn (ValidationError $e): string => $e->message, $this->errors);
    }

    /**
     * @return array{
     *     line: int, name: string, surname: string, email: string,
     *     valid: bool, status: string, errors: list<string>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'line'    => $this->line,
            'name'    => $this->record->name,
            'surname' => $this->record->surname,
            'email'   => $this->record->email,
            'valid'   => $this->isValid(),
            'status'  => $this->isValid() ? 'Valid' : 'Error',
            'errors'  => $this->messages(),
        ];
    }
}
