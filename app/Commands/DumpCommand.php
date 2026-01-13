<?php

namespace App\Commands;

use App\Models\Server;
use App\Services\ConnectionTester;
use App\Services\DumpService;
use App\Services\ServerManager;
use App\ValueObjects\DumpOptions;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multisearch;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class DumpCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dump
                            {--server= : Server name to dump from}
                            {--database= : Database name to dump}
                            {--tables= : Comma-separated list of tables (empty = all tables)}
                            {--schema-only : Export only schema without data}
                            {--data-only : Export only data without schema}
                            {--drop-tables : Include DROP TABLE statements}
                            {--gzip : Compress output with gzip}
                            {--output= : Output file path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dump MySQL database to SQL file';

    /**
     * Execute the console command.
     */
    public function handle(
        ServerManager $serverManager,
        ConnectionTester $connectionTester,
        DumpService $dumpService
    ): int {
        // Check if any flags are provided (direct mode)
        if ($this->option('server') || $this->option('database')) {
            return $this->directMode($serverManager, $connectionTester, $dumpService);
        }

        // Interactive mode
        return $this->interactiveMode($serverManager, $connectionTester, $dumpService);
    }

    /**
     * Interactive mode for dumping database.
     */
    protected function interactiveMode(
        ServerManager $serverManager,
        ConnectionTester $connectionTester,
        DumpService $dumpService
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
            // Find the actual database name (case-insensitive match)
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
            label: 'Select database',
            options: fn (string $value) => strlen($value) > 0
                ? array_values(array_filter($databases, fn ($db) => stripos($db, $value) !== false))
                : $databases,
            placeholder: 'Search databases...'
        );

        // Get list of tables
        $tables = $connectionTester->listTables($server, $database);

        if (empty($tables)) {
            $this->error("No tables found in database '{$database}'.");
            $this->line('Verify that:');
            $this->line('  • The database contains tables');
            $this->line('  • The MySQL user has proper SELECT permissions');

            return self::FAILURE;
        }

        // Ask for table selection mode
        $selectionMode = select(
            label: 'Table selection',
            options: [
                'all' => 'All tables',
                'specific' => 'Select specific tables (searchable)',
            ],
            default: 'all'
        );

        if ($selectionMode === 'all') {
            $selectedTables = $tables;
        } else {
            $selectedTables = multisearch(
                label: 'Search and select tables',
                placeholder: 'Type to search tables...',
                options: fn (string $value) => strlen($value) > 0
                    ? array_values(array_filter($tables, fn ($table) => stripos($table, $value) !== false))
                    : $tables,
                hint: 'Use space to select/deselect, arrows to navigate'
            );
        }

        // Select export type
        $exportType = select(
            label: 'Export type',
            options: [
                'both' => 'Schema and data',
                'schema' => 'Schema only',
                'data' => 'Data only',
            ]
        );

        $schemaOnly = $exportType === 'schema';
        $dataOnly = $exportType === 'data';

        // Confirm drop tables
        $dropTables = confirm(
            label: 'Include DROP TABLE statements?',
            default: false
        );

        // Confirm gzip compression
        $gzip = confirm(
            label: 'Compress with gzip?',
            default: false
        );

        // Ask for output path
        $outputPath = text(
            label: 'Output path',
            default: getcwd(),
            validate: function (string $value) {
                if (empty($value)) {
                    return 'Output path is required.';
                }

                $validationError = $this->validateOutputPath($value);
                if ($validationError) {
                    return $validationError;
                }

                return null;
            }
        );

        // Check if file already exists
        if (is_file($outputPath)) {
            $this->warn("Warning: File already exists at: {$outputPath}");
            if (! confirm(label: 'Overwrite existing file?', default: false)) {
                $this->info('Dump cancelled.');

                return self::SUCCESS;
            }
        }

        // Build dump options
        try {
            $dumpOptions = new DumpOptions(
                database: $database,
                tables: $selectedTables,
                schemaOnly: $schemaOnly,
                dataOnly: $dataOnly,
                dropTables: $dropTables,
                gzip: $gzip,
                outputPath: $outputPath
            );
        } catch (\InvalidArgumentException $e) {
            $this->error('Invalid dump options: '.$e->getMessage());

            return self::FAILURE;
        }

        // Execute dump
        return $this->executeDump($server, $dumpOptions, $dumpService);
    }

    /**
     * Direct mode using CLI flags.
     */
    protected function directMode(
        ServerManager $serverManager,
        ConnectionTester $connectionTester,
        DumpService $dumpService
    ): int {
        $serverName = $this->option('server');
        $database = $this->option('database');

        // Validate required options
        if (! $serverName) {
            $this->error('Server name is required. Use --server option.');
            $this->line('Example: ./mysql-dumper dump --server=myserver --database=mydb');

            return self::FAILURE;
        }

        if (! $database) {
            $this->error('Database name is required. Use --database option.');
            $this->line('Example: ./mysql-dumper dump --server=myserver --database=mydb');

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

        // Parse and validate tables option
        $tablesString = $this->option('tables');
        $tables = [];

        if ($tablesString) {
            $tables = array_map('trim', explode(',', $tablesString));
            $tables = array_filter($tables); // Remove empty entries

            // Validate table names
            if (! $this->validateTableNames($tables)) {
                $this->error('Invalid table names detected. Table names can only contain alphanumeric characters, underscores, and hyphens.');

                return self::FAILURE;
            }

            // Verify tables exist in database
            $availableTables = $connectionTester->listTables($server, $database);
            $invalidTables = array_diff($tables, $availableTables);

            if (! empty($invalidTables)) {
                $this->error('The following tables do not exist in database \''.$database.'\':');
                foreach ($invalidTables as $table) {
                    $this->line("  • {$table}");
                }
                $this->newLine();
                $this->line('Available tables:');
                foreach ($availableTables as $table) {
                    $this->line("  • {$table}");
                }

                return self::FAILURE;
            }
        }

        // Validate schema-only and data-only aren't both set
        if ($this->option('schema-only') && $this->option('data-only')) {
            $this->error('Cannot use both --schema-only and --data-only flags.');

            return self::FAILURE;
        }

        // Validate output path
        $outputPath = $this->option('output');
        if ($outputPath) {
            $validationError = $this->validateOutputPath($outputPath);
            if ($validationError) {
                $this->error($validationError);

                return self::FAILURE;
            }

            // Check if file already exists
            if (is_file($outputPath)) {
                $this->warn("Warning: Output file already exists: {$outputPath}");
                $this->warn('The file will be overwritten.');
            }
        }

        // Build dump options
        try {
            $dumpOptions = new DumpOptions(
                database: $database,
                tables: $tables,
                schemaOnly: $this->option('schema-only'),
                dataOnly: $this->option('data-only'),
                dropTables: $this->option('drop-tables'),
                gzip: $this->option('gzip'),
                outputPath: $outputPath
            );
        } catch (\InvalidArgumentException $e) {
            $this->error('Invalid dump options: '.$e->getMessage());

            return self::FAILURE;
        }

        // Execute dump
        return $this->executeDump($server, $dumpOptions, $dumpService);
    }

    /**
     * Execute the dump operation.
     */
    protected function executeDump(Server $server, DumpOptions $dumpOptions, DumpService $dumpService): int
    {
        // Perform pre-dump validations
        $preCheckErrors = $this->performPreDumpChecks($dumpOptions);
        if (! empty($preCheckErrors)) {
            foreach ($preCheckErrors as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->info('Starting database dump...');

        // Execute dump
        $result = $dumpService->dump($server, $dumpOptions);

        if (! $result->success) {
            $this->error("Dump failed: {$result->error}");
            $this->newLine();
            $this->line('Troubleshooting tips:');
            $this->line('  • Verify mysqldump is installed: which mysqldump');
            $this->line('  • Check MySQL server logs for errors');
            $this->line('  • Ensure sufficient permissions to read database');
            $this->line('  • Verify there is enough disk space available');

            return self::FAILURE;
        }

        // Display success message
        $this->info('✓ Dump completed successfully!');
        $this->line('  File: '.$result->filePath);
        $this->line('  Size: '.$result->formatFileSize());
        $this->line('  Duration: '.round($result->duration, 2).'s');

        return self::SUCCESS;
    }

    /**
     * Validate table names to prevent injection attacks.
     *
     * @param  array<string>  $tables
     */
    protected function validateTableNames(array $tables): bool
    {
        foreach ($tables as $table) {
            // Table names should only contain alphanumeric characters, underscores, and hyphens
            if (! preg_match('/^[a-zA-Z0-9_-]+$/', $table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate output path for dump file.
     *
     * @return string|null Error message if validation fails, null if valid
     */
    protected function validateOutputPath(string $outputPath): ?string
    {
        // Check if path is absolute or relative
        $isAbsolute = str_starts_with($outputPath, '/') || preg_match('/^[a-zA-Z]:/', $outputPath);

        if ($isAbsolute) {
            $directory = dirname($outputPath);
            $isFile = ! str_ends_with($outputPath, '/');
        } else {
            // For relative paths, check current directory
            $directory = getcwd();
            if (str_contains($outputPath, '/')) {
                $directory = dirname($outputPath);
            }
            $isFile = ! str_ends_with($outputPath, '/');
        }

        // Validate directory exists
        if (! is_dir($directory)) {
            return "Output directory does not exist: {$directory}";
        }

        // Validate directory is writable
        if (! is_writable($directory)) {
            return "Output directory is not writable: {$directory}";
        }

        // If it's a file path, check if parent directory is writable
        if ($isFile && file_exists($outputPath) && ! is_writable($outputPath)) {
            return "Output file is not writable: {$outputPath}";
        }

        return null;
    }

    /**
     * Perform pre-dump validation checks.
     *
     * @return array<string> Array of error messages (empty if all checks pass)
     */
    protected function performPreDumpChecks(DumpOptions $dumpOptions): array
    {
        $errors = [];

        // Check if output directory has sufficient disk space (minimum 100MB)
        $outputDir = $dumpOptions->outputPath
            ? (is_dir($dumpOptions->outputPath) ? $dumpOptions->outputPath : dirname($dumpOptions->outputPath))
            : getcwd();

        $freeSpace = disk_free_space($outputDir);
        if ($freeSpace === false) {
            $errors[] = "Unable to determine free disk space for: {$outputDir}";
        } elseif ($freeSpace < 100 * 1024 * 1024) { // Less than 100MB
            $sizeMB = round($freeSpace / (1024 * 1024), 2);
            $errors[] = "Low disk space warning: Only {$sizeMB}MB available in {$outputDir}. Consider freeing up space before proceeding.";
        }

        return $errors;
    }
}
