<?php

namespace App\ValueObjects;

class ImportResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $database,
        public readonly string $filePath,
        public readonly float $duration,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Create a successful import result
     */
    public static function success(string $database, string $filePath, float $duration): self
    {
        return new self(
            success: true,
            database: $database,
            filePath: $filePath,
            duration: $duration,
            error: null,
        );
    }

    /**
     * Create a failed import result
     */
    public static function failure(string $error, string $database = '', string $filePath = ''): self
    {
        return new self(
            success: false,
            database: $database,
            filePath: $filePath,
            duration: 0.0,
            error: $error,
        );
    }
}
