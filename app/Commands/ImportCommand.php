<?php

namespace App\Commands;

use App\Models\Server;
use App\Services\ConnectionTester;
use App\Services\ImportService;
use App\Services\ServerManager;
use App\ValueObjects\ImportOptions;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ImportCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import
                            {--server= : Server name to import to}
                            {--database= : Database name to import to}
                            {--file= : Path to SQL file (.sql or .sql.gz)}
                            {--force : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import SQL file to MySQL database';

    /**
     * Execute the console command.
     */
    public function handle(
        ServerManager $serverManager,
        ConnectionTester $connectionTester,
        ImportService $importService
    ): int {
        // Check if any flags are provided (direct mode)
        if ($this->option('server') || $this->option('database') || $this->option('file')) {
            return $this->directMode($serverManager, $connectionTester, $importService);
        }

        // Interactive mode
        return $this->interactiveMode($serverManager, $connectionTester, $importService);
    }

    /**
     * Interactive mode for importing database.
     */
    protected function interactiveMode(
        ServerManager $serverManager,
        ConnectionTester $connectionTester,
        ImportService $importService
    ): int {
        // Select server
        $servers = $serverManager->all();

        if ($servers->isEmpty()) {
            $this->error('No servers configured. Please add a server first using: ./mysql-dumper server add');

            return self::FAILURE;
        }

        $serverOptions = $servers->mapWithKeys(function (Server $server) {
            return [$server->name => "{$server->name} ({$server->host}:{$server->port})"];
        })->toArray();

        $serverName = select(
            label: 'Select server',
            options: $serverOptions
        );

        $server = $serverManager->findByName($serverName);

        if (! $server) {
            $this->error("Server '{$serverName}' not found.");

            return self::FAILURE;
        }

        // Test connection
        $this->info("Testing connection to '{$server->name}'...");
        $testResult = $connectionTester->test($server);

        if (! $testResult['success']) {
            $this->error("Connection failed: {$testResult['message']}");
            $this->newLine();
            $this->line('Troubleshooting tips:');
            $this->line('  • Verify server credentials with: ./mysql-dumper server test '.$server->name);
            $this->line('  • Check if MySQL server is running');
            $this->line('  • Verify network connectivity and firewall rules');
            $this->line('  • Ensure SSH tunnel is configured correctly (if used)');

            return self::FAILURE;
        }

        // Get list of databases
        $databases = $connectionTester->listDatabases($server);

        if (empty($databases)) {
            $this->error('No databases found on this server.');
            $this->line('Verify that:');
            $this->line('  • The MySQL user has proper permissions');
            $this->line('  • At least one database exists on the server');

            return self::FAILURE;
        }

        // Reorder databases to put server's configured database first (if set)
        if ($server->database) {
            $actualDatabase = null;
            foreach ($databases as $db) {
                if (strcasecmp($db, $server->database) === 0) {
                    $actualDatabase = $db;
                    break;
                }
            }

            if ($actualDatabase !== null) {
                $databases = array_values(array_diff($databases, [$actualDatabase]));
                array_unshift($databases, $actualDatabase);
            }
        }

        // Use searchable database selector
        $database = search(
            label: 'Select database to import to',
            options: fn (string $value) => strlen($value) > 0
                ? array_values(array_filter($databases, fn ($db) => stripos($db, $value) !== false))
                : $databases,
            placeholder: 'Search databases...'
        );

        // Ask for file path
        $filePath = text(
            label: 'SQL file path',
            placeholder: '/path/to/dump.sql or /path/to/dump.sql.gz',
            validate: function (string $value) {
                if (empty($value)) {
                    return 'File path is required.';
                }

                $validationError = $this->validateFilePath($value);
                if ($validationError) {
                    return $validationError;
                }

                return null;
            }
        );

        // Confirm import
        $this->newLine();
        $this->line('Import Summary:');
        $this->line("  Server: {$server->name} ({$server->host}:{$server->port})");
        $this->line("  Database: {$database}");
        $this->line("  File: {$filePath}");
        $this->newLine();

        if (! confirm(label: 'Proceed with import?', default: false)) {
            $this->info('Import cancelled.');

            return self::SUCCESS;
        }

        // Build import options
        try {
            $importOptions = new ImportOptions(
                database: $database,
                filePath: $filePath,
                forceImport: false
            );
        } catch (\InvalidArgumentException $e) {
            $this->error('Invalid import options: '.$e->getMessage());

            return self::FAILURE;
        }

        // Execute import
        return $this->executeImport($server, $importOptions, $importService);
    }

    /**
     * Direct mode using CLI flags.
     */
    protected function directMode(
        ServerManager $serverManager,
        ConnectionTester $connectionTester,
        ImportService $importService
    ): int {
        $serverName = $this->option('server');
        $database = $this->option('database');
        $filePath = $this->option('file');

        // Validate required options
        if (! $serverName) {
            $this->error('Server name is required. Use --server option.');
            $this->line('Example: ./mysql-dumper import --server=myserver --database=mydb --file=dump.sql');

            return self::FAILURE;
        }

        if (! $database) {
            $this->error('Database name is required. Use --database option.');
            $this->line('Example: ./mysql-dumper import --server=myserver --database=mydb --file=dump.sql');

            return self::FAILURE;
        }

        if (! $filePath) {
            $this->error('File path is required. Use --file option.');
            $this->line('Example: ./mysql-dumper import --server=myserver --database=mydb --file=dump.sql');

            return self::FAILURE;
        }

        // Validate file path early (before connection test)
        $validationError = $this->validateFilePath($filePath);
        if ($validationError) {
            $this->error($validationError);

            return self::FAILURE;
        }

        // Find server
        $server = $serverManager->findByName($serverName);

        if (! $server) {
            $this->error("Server '{$serverName}' not found.");
            $availableServers = $serverManager->all();
            if ($availableServers->isNotEmpty()) {
                $this->line('Available servers: '.$availableServers->pluck('name')->join(', '));
            } else {
                $this->line('No servers configured. Use: ./mysql-dumper server add');
            }

            return self::FAILURE;
        }

        // Test connection
        $this->info("Testing connection to '{$server->name}'...");
        $testResult = $connectionTester->test($server);

        if (! $testResult['success']) {
            $this->error("Connection failed: {$testResult['message']}");
            $this->newLine();
            $this->line('Troubleshooting tips:');
            $this->line('  • Verify server credentials with: ./mysql-dumper server test '.$serverName);
            $this->line('  • Check if MySQL server is running');
            $this->line('  • Verify network connectivity and firewall rules');
            $this->line('  • Ensure SSH tunnel is configured correctly (if used)');

            return self::FAILURE;
        }

        // Verify database exists
        $databases = $connectionTester->listDatabases($server);
        if (! in_array($database, $databases, true)) {
            $this->error("Database '{$database}' not found on server '{$serverName}'.");
            $this->newLine();
            $this->line('Available databases:');
            foreach ($databases as $db) {
                $this->line("  • {$db}");
            }

            return self::FAILURE;
        }

        // Show warning if not in force mode
        if (! $this->option('force')) {
            $this->warn('Warning: This will import data into the database. Existing data may be affected.');
            $this->line('Use --force to skip this warning.');

            if (! confirm(label: 'Continue with import?', default: false)) {
                $this->info('Import cancelled.');

                return self::SUCCESS;
            }
        }

        // Build import options
        try {
            $importOptions = new ImportOptions(
                database: $database,
                filePath: $filePath,
                forceImport: $this->option('force')
            );
        } catch (\InvalidArgumentException $e) {
            $this->error('Invalid import options: '.$e->getMessage());

            return self::FAILURE;
        }

        // Execute import
        return $this->executeImport($server, $importOptions, $importService);
    }

    /**
     * Execute the import operation.
     */
    protected function executeImport(Server $server, ImportOptions $importOptions, ImportService $importService): int
    {
        // Perform pre-import validations
        $preCheckErrors = $this->performPreImportChecks($importOptions);
        if (! empty($preCheckErrors)) {
            foreach ($preCheckErrors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info('Starting database import...');

        // Execute import
        $result = $importService->import($server, $importOptions);

        if (! $result->success) {
            $this->error("Import failed: {$result->error}");
            $this->newLine();
            $this->line('Troubleshooting tips:');
            $this->line('  • Verify mysql is installed: which mysql');
            $this->line('  • Check MySQL server logs for errors');
            $this->line('  • Ensure sufficient permissions to write to database');
            $this->line('  • Verify the SQL file is valid and not corrupted');

            return self::FAILURE;
        }

        // Display success message
        $this->info('✓ Import completed successfully!');
        $this->line('  Database: '.$result->database);
        $this->line('  File: '.$result->filePath);
        $this->line('  Duration: '.round($result->duration, 2).'s');

        return self::SUCCESS;
    }

    /**
     * Validate file path for import.
     *
     * @return string|null Error message if validation fails, null if valid
     */
    protected function validateFilePath(string $filePath): ?string
    {
        if (! file_exists($filePath)) {
            return "File does not exist: {$filePath}";
        }

        if (! is_file($filePath)) {
            return "Path is not a file: {$filePath}";
        }

        if (! is_readable($filePath)) {
            return "File is not readable: {$filePath}";
        }

        if (! preg_match('/\.(sql|sql\.gz)$/i', $filePath)) {
            return "Invalid file extension. Only .sql and .sql.gz files are supported: {$filePath}";
        }

        if (filesize($filePath) === 0) {
            return "File is empty: {$filePath}";
        }

        return null;
    }

    /**
     * Perform pre-import validation checks.
     *
     * @return array<string> Array of error messages (empty if all checks pass)
     */
    protected function performPreImportChecks(ImportOptions $importOptions): array
    {
        $errors = [];

        // Check if file is very large (warn if > 100MB)
        $fileSize = filesize($importOptions->filePath);
        if ($fileSize > 100 * 1024 * 1024) {
            $sizeMB = round($fileSize / (1024 * 1024), 2);
            $this->warn("Large file warning: File size is {$sizeMB}MB. Import may take a while.");
        }

        return $errors;
    }
}
