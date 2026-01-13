<?php

namespace App\Services;

use App\Models\Server;
use RuntimeException;

class SshTunnelService
{
    /**
     * Shared registry for storing pipes across method calls
     */
    private static array $pipesRegistry = [];

    /**
     * Create an SSH tunnel for the given server
     *
     * @return array{host: string, port: int, process: resource}
     * @throws RuntimeException
     */
    public function createTunnel(Server $server): array
    {
        if (! $server->hasSshTunnel()) {
            throw new RuntimeException('Server does not have SSH tunnel configured.');
        }

        // Check if SSH binary exists
        if (! $this->isSshAvailable()) {
            throw new RuntimeException('SSH binary not found. Please ensure OpenSSH is installed.');
        }

        $sshOptions = $server->getSshOptions();
        $localPort = $this->findAvailablePort();

        // Build SSH command
        $command = $this->buildSshCommand(
            localPort: $localPort,
            remoteHost: $server->host,
            remotePort: $server->port,
            sshHost: $sshOptions['host'],
            sshPort: $sshOptions['port'],
            sshUsername: $sshOptions['username'],
            sshPassword: $sshOptions['password'],
            keyPath: $sshOptions['key_path'],
        );

        // Start SSH tunnel
        $descriptorSpec = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start SSH tunnel process.');
        }

        // Close stdin
        fclose($pipes[0]);

        // Give the tunnel time to establish
        // Increased from 0.5s to 2s for more reliable connection establishment
        usleep(2000000); // 2 seconds

        // Check if process is still running
        $status = proc_get_status($process);
        if (! $status['running']) {
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            throw new RuntimeException("SSH tunnel failed to establish: {$stderr}");
        }

        // Store pipes for later cleanup
        $this->storePipes($process, $pipes);

        return [
            'host' => '127.0.0.1',
            'port' => $localPort,
            'process' => $process,
        ];
    }

    /**
     * Close an SSH tunnel
     */
    public function closeTunnel($process): void
    {
        if (! is_resource($process)) {
            return;
        }

        $status = proc_get_status($process);

        if ($status['running']) {
            // Try graceful termination first (SIGTERM)
            proc_terminate($process);

            // Wait a moment for graceful shutdown
            usleep(100000); // 0.1 seconds

            // Check if still running
            $status = proc_get_status($process);
            if ($status['running']) {
                // Force kill (SIGKILL)
                proc_terminate($process, 9);
            }
        }

        // Close the process
        proc_close($process);

        // Clean up stored pipes if any
        $this->cleanupPipes($process);
    }

    /**
     * Check if SSH binary is available
     */
    private function isSshAvailable(): bool
    {
        $output = [];
        $returnCode = 0;

        exec('which ssh 2>/dev/null', $output, $returnCode);

        return $returnCode === 0 && ! empty($output);
    }

    /**
     * Find an available local port
     */
    private function findAvailablePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        if ($socket === false) {
            throw new RuntimeException('Failed to create socket for port detection.');
        }

        // Bind to port 0 to let the OS choose an available port
        if (! socket_bind($socket, '127.0.0.1', 0)) {
            socket_close($socket);
            throw new RuntimeException('Failed to bind socket for port detection.');
        }

        // Get the assigned port
        if (! socket_getsockname($socket, $address, $port)) {
            socket_close($socket);
            throw new RuntimeException('Failed to get socket name for port detection.');
        }

        socket_close($socket);

        return $port;
    }

    /**
     * Build SSH tunnel command
     */
    private function buildSshCommand(
        int $localPort,
        string $remoteHost,
        int $remotePort,
        string $sshHost,
        int $sshPort,
        string $sshUsername,
        ?string $sshPassword,
        ?string $keyPath,
    ): string {
        $command = sprintf(
            'ssh -N -L %d:%s:%d -p %d %s@%s',
            $localPort,
            $remoteHost,
            $remotePort,
            $sshPort,
            escapeshellarg($sshUsername),
            escapeshellarg($sshHost)
        );

        // Add key-based auth if key path is provided
        if ($keyPath) {
            $command .= ' -i ' . escapeshellarg($keyPath);
        }

        // Add common options
        $command .= ' -o StrictHostKeyChecking=no';
        $command .= ' -o UserKnownHostsFile=/dev/null';
        $command .= ' -o ServerAliveInterval=60';
        $command .= ' -o ServerAliveCountMax=3';

        // If password is provided and sshpass is available, use it
        if ($sshPassword && $this->isSshpassAvailable()) {
            $command = sprintf(
                'sshpass -p %s %s',
                escapeshellarg($sshPassword),
                $command
            );
        }

        return $command;
    }

    /**
     * Check if sshpass is available for password authentication
     */
    private function isSshpassAvailable(): bool
    {
        $output = [];
        $returnCode = 0;

        exec('which sshpass 2>/dev/null', $output, $returnCode);

        return $returnCode === 0 && ! empty($output);
    }

    /**
     * Check if a port is listening on the given host
     */
    private function isPortListening(string $host, int $port): bool
    {
        $connection = @fsockopen($host, $port, $errno, $errstr, 0.1);

        if ($connection) {
            fclose($connection);
            return true;
        }

        return false;
    }

    /**
     * Store pipes for later cleanup
     */
    private function storePipes($process, array $pipes): void
    {
        // Store pipes in class property for cleanup
        self::$pipesRegistry[get_resource_id($process)] = $pipes;
    }

    /**
     * Clean up stored pipes
     */
    private function cleanupPipes($process): void
    {
        $id = get_resource_id($process);

        if (isset(self::$pipesRegistry[$id])) {
            foreach (self::$pipesRegistry[$id] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            unset(self::$pipesRegistry[$id]);
        }
    }
}
