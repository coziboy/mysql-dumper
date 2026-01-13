<?php

namespace App\ValueObjects;

use InvalidArgumentException;

class DumpOptions
{
    public function __construct(
        public readonly string $database,
        public readonly array $tables = [],
        public readonly bool $schemaOnly = false,
        public readonly bool $dataOnly = false,
        public readonly bool $dropTables = false,
        public readonly bool $gzip = false,
        public readonly ?string $outputPath = null,
    ) {
        $this->validate();
    }

    /**
     * Validate the dump options
     *
     * @throws InvalidArgumentException
     */
    private function validate(): void
    {
        if ($this->schemaOnly && $this->dataOnly) {
            throw new InvalidArgumentException(
                'Cannot set both schemaOnly and dataOnly to true. Choose one or neither.'
            );
        }

        if (empty($this->database)) {
            throw new InvalidArgumentException('Database name cannot be empty.');
        }
    }
}
