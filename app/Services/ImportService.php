<?php

namespace App\Services;

use App\Models\Server;
use App\ValueObjects\ImportOptions;
use App\ValueObjects\ImportResult;
use RuntimeException;

use function Laravel\Prompts\spin;

class ImportService
{
    public function __construct(
        private readonly SshTunnelService $sshTunnelService,
    ) {
    }

    /**
     * Perform a database import
     *
     * @throws RuntimeException
     */
    public function import(Server $server, ImportOptions $options): ImportResult
    {
        $startTime = microtime(true);
        $tunnel = null;
        $connectionOverride = [];

        try {
            // Check if mysql binary exists
            if (! $this->checkMysqlExists()) {
                return ImportResult::failure('mysql binary not found. Please ensure MySQL client is installed.', $options->database, $options->filePath);
            }

            // Setup SSH tunnel if needed
            if ($server->hasSshTunnel()) {
                $tunnel = $this->sshTunnelService->createTunnel($server);
                $connectionOverride = [
                    'host' => $tunnel['host'],
                    'port' => $tunnel['port'],
                ];
            }

            // Build mysql import command
            $command = $this->buildCommand($server, $options, $connectionOverride);

            // Detect if file is gzipped and adjust command accordingly
            if ($this->isGzipped($options->filePath)) {
                $command = 'gunzip < '.escapeshellarg($options->filePath).' | '.$command;
            } else {
                $command .= ' < '.escapeshellarg($options->filePath);
            }

            // Execute command with progress spinner
            $output = [];
            $returnCode = 0;

            // Redirect stderr to stdout to capture error messages
            $command .= ' 2>&1';

            spin(
                function () use ($command, &$output, &$returnCode) {
                    exec($command, $output, $returnCode);
                },
                'Importing database...'
            );

            // Check if command succeeded
            if ($returnCode !== 0) {
                $errorMessage = implode("\n", $output);

                return ImportResult::failure("mysql import command failed: {$errorMessage}", $options->database, $options->filePath);
            }

            $duration = microtime(true) - $startTime;

            return ImportResult::success($options->database, $options->filePath, $duration);
        } catch (\Exception $e) {
            return ImportResult::failure($e->getMessage(), $options->database, $options->filePath);
        } finally {
            // Cleanup SSH tunnel if it was created
            if ($tunnel !== null) {
                $this->sshTunnelService->closeTunnel($tunnel['process']);
            }
        }
    }

    /**
     * Build mysql import command
     */
    public function buildCommand(Server $server, ImportOptions $options, array $connectionOverride = []): string
    {
        // Use connection override (for SSH tunnel) or default server settings
        $host = $connectionOverride['host'] ?? $server->host;
        $port = $connectionOverride['port'] ?? $server->port;

        // Base command
        $command = sprintf(
            'mysql --host=%s --port=%d --user=%s',
            escapeshellarg($host),
            $port,
            escapeshellarg($server->username)
        );

        // Add password if set
        if ($server->password) {
            $command .= ' --password='.escapeshellarg($server->password);
        }

        // Add charset flag to ensure proper encoding
        $command .= ' --default-character-set='.escapeshellarg($server->charset);

        // Add database name
        $command .= ' '.escapeshellarg($options->database);

        return $command;
    }

    /**
     * Check if mysql binary exists
     */
    public function checkMysqlExists(): bool
    {
        $output = [];
        $returnCode = 0;

        exec('which mysql 2>/dev/null', $output, $returnCode);

        return $returnCode === 0 && ! empty($output);
    }

    /**
     * Detect if file is gzipped
     */
    public function isGzipped(string $filePath): bool
    {
        // Check if file exists first
        if (! file_exists($filePath)) {
            return false;
        }

        // Check file extension
        if (preg_match('/\.gz$/i', $filePath)) {
            return true;
        }

        // Check magic bytes (gzip files start with 1f 8b)
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return false;
        }

        $bytes = fread($handle, 2);
        fclose($handle);

        if ($bytes === false || strlen($bytes) < 2) {
            return false;
        }

        return bin2hex($bytes) === '1f8b';
    }
}
