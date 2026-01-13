<?php

namespace App\ValueObjects;

class DumpResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $filePath,
        public readonly int $fileSize,
        public readonly float $duration,
        public readonly ?string $error = null,
    ) {
    }

    /**
     * Create a successful dump result
     */
    public static function success(string $filePath, int $fileSize, float $duration): self
    {
        return new self(
            success: true,
            filePath: $filePath,
            fileSize: $fileSize,
            duration: $duration,
            error: null,
        );
    }

    /**
     * Create a failed dump result
     */
    public static function failure(string $error): self
    {
        return new self(
            success: false,
            filePath: '',
            fileSize: 0,
            duration: 0.0,
            error: $error,
        );
    }

    /**
     * Format file size in human-readable format
     */
    public function formatFileSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $this->fileSize;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2) . ' ' . $units[$unitIndex];
    }
}
