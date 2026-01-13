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

            return self::FAILURE;
        }

        // Get list of databases
        $databases = $connectionTester->listDatabases($server);

        if (empty($databases)) {
            $this->error('No databases found on this server.');

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
            validate: fn (string $value) => match (true) {
                empty($value) => 'Output path is required.',
                ! is_dir($value) && ! is_dir(dirname($value)) => 'Output directory does not exist.',
                default => null
            }
        );

        // Build dump options
        $dumpOptions = new DumpOptions(
            database: $database,
            tables: $selectedTables,
            schemaOnly: $schemaOnly,
            dataOnly: $dataOnly,
            dropTables: $dropTables,
            gzip: $gzip,
            outputPath: $outputPath
        );

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

            return self::FAILURE;
        }

        if (! $database) {
            $this->error('Database name is required. Use --database option.');

            return self::FAILURE;
        }

        // Find server
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

            return self::FAILURE;
        }

        // Parse tables option
        $tablesString = $this->option('tables');
        $tables = $tablesString ? array_map('trim', explode(',', $tablesString)) : [];

        // Validate schema-only and data-only aren't both set
        if ($this->option('schema-only') && $this->option('data-only')) {
            $this->error('Cannot use both --schema-only and --data-only flags.');

            return self::FAILURE;
        }

        // Build dump options
        $dumpOptions = new DumpOptions(
            database: $database,
            tables: $tables,
            schemaOnly: $this->option('schema-only'),
            dataOnly: $this->option('data-only'),
            dropTables: $this->option('drop-tables'),
            gzip: $this->option('gzip'),
            outputPath: $this->option('output')
        );

        // Execute dump
        return $this->executeDump($server, $dumpOptions, $dumpService);
    }

    /**
     * Execute the dump operation.
     */
    protected function executeDump(Server $server, DumpOptions $dumpOptions, DumpService $dumpService): int
    {
        $this->info('Starting database dump...');

        // Execute dump
        $result = $dumpService->dump($server, $dumpOptions);

        if (! $result->success) {
            $this->error("Dump failed: {$result->error}");

            return self::FAILURE;
        }

        // Display success message
        $this->info('✓ Dump completed successfully!');
        $this->line('  File: '.$result->filePath);
        $this->line('  Size: '.$result->formatFileSize());
        $this->line('  Duration: '.round($result->duration, 2).'s');

        return self::SUCCESS;
    }
}
