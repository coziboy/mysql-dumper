<?php

namespace App\Services;

use App\Models\Server;
use PDO;
use PDOException;

class ConnectionTester
{
    public function __construct(
        private readonly SshTunnelService $sshTunnelService,
    ) {
    }

    /**
     * Test connection to a server.
     *
     * @return array{success: bool, message: string, details?: array}
     */
    public function test(Server $server): array
    {
        $connection = null;

        try {
            $startTime = microtime(true);

            $connection = $this->createConnection($server);
            $pdo = $connection['pdo'];

            // Test the connection by running a simple query
            $pdo->query('SELECT 1');

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'duration_ms' => $duration,
                    'server_info' => $pdo->getAttribute(PDO::ATTR_SERVER_INFO),
                    'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                    'driver' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                ],
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
                'details' => [
                    'error_code' => $e->getCode(),
                    'error_info' => $e->errorInfo ?? null,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        } finally {
            if ($connection && $connection['tunnel']) {
                $this->sshTunnelService->closeTunnel($connection['tunnel']);
            }
        }
    }

    /**
     * Test connection with custom credentials.
     *
     * @return array{success: bool, message: string, details?: array}
     */
    public function testWithCredentials(string $host, int $port, string $username, string $password, ?string $database = null): array
    {
        try {
            $startTime = microtime(true);

            $dsn = "mysql:host={$host};port={$port}";
            if ($database) {
                $dsn .= ";dbname={$database}";
            }
            $dsn .= ';charset=utf8mb4';

            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            $pdo->query('SELECT 1');

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            return [
                'success' => true,
                'message' => 'Connection successful',
                'details' => [
                    'duration_ms' => $duration,
                    'server_info' => $pdo->getAttribute(PDO::ATTR_SERVER_INFO),
                    'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                ],
            ];
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
                'details' => [
                    'error_code' => $e->getCode(),
                ],
            ];
        }
    }

    /**
     * Check if a database exists on the server.
     */
    public function databaseExists(Server $server, string $database): bool
    {
        $connection = null;

        try {
            $connection = $this->createConnection($server);
            $pdo = $connection['pdo'];

            $stmt = $pdo->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $stmt->execute([$database]);

            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            return false;
        } finally {
            if ($connection && $connection['tunnel']) {
                $this->sshTunnelService->closeTunnel($connection['tunnel']);
            }
        }
    }

    /**
     * Get list of databases on the server.
     *
     * @return array<string>
     */
    public function listDatabases(Server $server): array
    {
        $connection = null;

        try {
            $connection = $this->createConnection($server);
            $pdo = $connection['pdo'];

            $stmt = $pdo->query('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA ORDER BY SCHEMA_NAME');

            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            return [];
        } finally {
            if ($connection && $connection['tunnel']) {
                $this->sshTunnelService->closeTunnel($connection['tunnel']);
            }
        }
    }

    /**
     * Get list of tables in a database.
     *
     * @return array<string>
     */
    public function listTables(Server $server, string $database): array
    {
        $connection = null;

        try {
            $connection = $this->createConnection($server);
            $pdo = $connection['pdo'];

            $stmt = $pdo->prepare('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
            $stmt->execute([$database]);

            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Exception $e) {
            return [];
        } finally {
            if ($connection && $connection['tunnel']) {
                $this->sshTunnelService->closeTunnel($connection['tunnel']);
            }
        }
    }

    /**
     * Get server status information.
     *
     * @return array<string, mixed>
     */
    public function getServerStatus(Server $server): array
    {
        $connection = null;

        try {
            $connection = $this->createConnection($server);
            $pdo = $connection['pdo'];

            $stmt = $pdo->query('SHOW STATUS');
            $status = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $status[$row['Variable_name']] = $row['Value'];
            }

            return $status;
        } catch (\Exception $e) {
            return [];
        } finally {
            if ($connection && $connection['tunnel']) {
                $this->sshTunnelService->closeTunnel($connection['tunnel']);
            }
        }
    }

    /**
     * Get server variables.
     *
     * @return array<string, mixed>
     */
    public function getServerVariables(Server $server): array
    {
        $connection = null;

        try {
            $connection = $this->createConnection($server);
            $pdo = $connection['pdo'];

            $stmt = $pdo->query('SHOW VARIABLES');
            $variables = [];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $variables[$row['Variable_name']] = $row['Value'];
            }

            return $variables;
        } catch (\Exception $e) {
            return [];
        } finally {
            if ($connection && $connection['tunnel']) {
                $this->sshTunnelService->closeTunnel($connection['tunnel']);
            }
        }
    }

    /**
     * Ping the server to check if connection is alive.
     */
    public function ping(Server $server): bool
    {
        $connection = null;

        try {
            $connection = $this->createConnection($server);
            $pdo = $connection['pdo'];
            $pdo->query('SELECT 1');

            return true;
        } catch (\Exception $e) {
            return false;
        } finally {
            if ($connection && $connection['tunnel']) {
                $this->sshTunnelService->closeTunnel($connection['tunnel']);
            }
        }
    }

    /**
     * Create a PDO connection for the server.
     *
     * @return array{pdo: PDO, tunnel: resource|null}
     */
    protected function createConnection(Server $server): array
    {
        $tunnel = null;
        $host = $server->host;
        $port = $server->port;

        // Setup SSH tunnel if needed
        if ($server->hasSshTunnel()) {
            $tunnel = $this->sshTunnelService->createTunnel($server);
            $host = $tunnel['host'];
            $port = $tunnel['port'];
        }

        // Build DSN with connection params (tunnel or direct)
        $dsn = "mysql:host={$host};port={$port}";

        if ($server->database) {
            $dsn .= ";dbname={$server->database}";
        }

        $dsn .= ";charset={$server->charset}";

        $pdo = new PDO($dsn, $server->username, $server->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_PERSISTENT => false,
        ]);

        return [
            'pdo' => $pdo,
            'tunnel' => $tunnel['process'] ?? null,
        ];
    }
}
