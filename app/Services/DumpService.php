<?php

namespace App\Services;

use App\Models\Server;
use App\ValueObjects\DumpOptions;
use App\ValueObjects\DumpResult;
use RuntimeException;

class DumpService
{
    public function __construct(
        private readonly SshTunnelService $sshTunnelService,
    ) {
    }

    /**
     * Perform a database dump
     *
     * @throws RuntimeException
     */
    public function dump(Server $server, DumpOptions $options): DumpResult
    {
        $startTime = microtime(true);
        $tunnel = null;
        $connectionOverride = [];

        try {
            // Check if mysqldump exists
            if (! $this->checkMysqldumpExists()) {
                return DumpResult::failure('mysqldump binary not found. Please ensure MySQL client is installed.');
            }

            // Setup SSH tunnel if needed
            if ($server->hasSshTunnel()) {
                $tunnel = $this->sshTunnelService->createTunnel($server);
                $connectionOverride = [
                    'host' => $tunnel['host'],
                    'port' => $tunnel['port'],
                ];
            }

            // Generate output path if not provided
            $outputPath = $options->outputPath ?? $this->generateOutputPath($options->database, $options->gzip);

            // Build mysqldump command
            $command = $this->buildCommand($server, $options, $connectionOverride);

            // Add output redirection
            if ($options->gzip) {
                $command .= ' | gzip > ' . escapeshellarg($outputPath);
            } else {
                $command .= ' > ' . escapeshellarg($outputPath);
            }

            // Execute command
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);

            // Check if command succeeded
            if ($returnCode !== 0) {
                $errorMessage = implode("\n", $output);
                return DumpResult::failure("mysqldump command failed: {$errorMessage}");
            }

            // Check if file was created
            if (! file_exists($outputPath)) {
                return DumpResult::failure("Dump file was not created at: {$outputPath}");
            }

            // Get file size
            $fileSize = filesize($outputPath);
            $duration = microtime(true) - $startTime;

            return DumpResult::success($outputPath, $fileSize, $duration);
        } catch (\Exception $e) {
            return DumpResult::failure($e->getMessage());
        } finally {
            // Cleanup SSH tunnel if it was created
            if ($tunnel !== null) {
                $this->sshTunnelService->closeTunnel($tunnel['process']);
            }
        }
    }

    /**
     * Build mysqldump command
     */
    public function buildCommand(Server $server, DumpOptions $options, array $connectionOverride = []): string
    {
        // Use connection override (for SSH tunnel) or default server settings
        $host = $connectionOverride['host'] ?? $server->host;
        $port = $connectionOverride['port'] ?? $server->port;

        // Base command
        $command = sprintf(
            'mysqldump --host=%s --port=%d --user=%s',
            escapeshellarg($host),
            $port,
            escapeshellarg($server->username)
        );

        // Add password if set
        if ($server->password) {
            $command .= ' --password=' . escapeshellarg($server->password);
        }

        // Add schema-only flag
        if ($options->schemaOnly) {
            $command .= ' --no-data';
        }

        // Add data-only flag
        if ($options->dataOnly) {
            $command .= ' --no-create-info';
        }

        // Add drop-tables flag
        if ($options->dropTables) {
            $command .= ' --add-drop-table';
        }

        // Add standard flags for consistent dumps
        $command .= ' --single-transaction';
        $command .= ' --routines';
        $command .= ' --triggers';
        $command .= ' --set-gtid-purged=OFF';

        // Add database name
        $command .= ' ' . escapeshellarg($options->database);

        // Add specific tables if specified
        if (! empty($options->tables)) {
            foreach ($options->tables as $table) {
                $command .= ' ' . escapeshellarg($table);
            }
        }

        return $command;
    }

    /**
     * Check if mysqldump binary exists
     */
    public function checkMysqldumpExists(): bool
    {
        $output = [];
        $returnCode = 0;

        exec('which mysqldump 2>/dev/null', $output, $returnCode);

        return $returnCode === 0 && ! empty($output);
    }

    /**
     * Generate output path for dump file
     *
     * Pattern: {database}_{YYYY-MM-DD_HHmmss}.sql(.gz)
     */
    public function generateOutputPath(string $database, bool $gzip): string
    {
        $timestamp = date('Y-m-d_His');
        $extension = $gzip ? '.sql.gz' : '.sql';

        return "{$database}_{$timestamp}{$extension}";
    }
}
