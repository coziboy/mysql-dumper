<?php

namespace App\ValueObjects;

use InvalidArgumentException;

class ImportOptions
{
    public function __construct(
        public readonly string $database,
        public readonly string $filePath,
        public readonly bool $forceImport = false,
    ) {
        $this->validate();
    }

    /**
     * Validate the import options
     *
     * @throws InvalidArgumentException
     */
    private function validate(): void
    {
        if (empty($this->database)) {
            throw new InvalidArgumentException('Database name cannot be empty.');
        }

        if (empty($this->filePath)) {
            throw new InvalidArgumentException('File path cannot be empty.');
        }

        if (! file_exists($this->filePath)) {
            throw new InvalidArgumentException("File does not exist: {$this->filePath}");
        }

        if (! is_readable($this->filePath)) {
            throw new InvalidArgumentException("File is not readable: {$this->filePath}");
        }

        if (! $this->hasValidExtension()) {
            throw new InvalidArgumentException(
                "Invalid file extension. Only .sql and .sql.gz files are supported: {$this->filePath}"
            );
        }

        if (filesize($this->filePath) === 0) {
            throw new InvalidArgumentException("File is empty: {$this->filePath}");
        }
    }

    /**
     * Check if file has valid extension
     */
    private function hasValidExtension(): bool
    {
        return preg_match('/\.(sql|sql\.gz)$/i', $this->filePath) === 1;
    }
}
