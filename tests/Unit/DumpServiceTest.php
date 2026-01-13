<?php

use App\Models\Server;
use App\Services\DumpService;
use App\Services\SshTunnelService;
use App\ValueObjects\DumpOptions;

beforeEach(function () {
    $this->sshTunnelService = Mockery::mock(SshTunnelService::class);
    $this->dumpService = new DumpService($this->sshTunnelService);

    $this->server = Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);
});

afterEach(function () {
    Mockery::close();
});

test('build command includes basic connection parameters', function () {
    $options = new DumpOptions(database: 'test_db');

    $command = $this->dumpService->buildCommand($this->server, $options);

    expect($command)->toContain('mysqldump')
        ->and($command)->toContain('--host=\'localhost\'')
        ->and($command)->toContain('--port=3306')
        ->and($command)->toContain('--user=\'root\'')
        ->and($command)->toContain('--password=\'secret\'')
        ->and($command)->toContain('\'test_db\'');
});

test('build command includes schema-only flag', function () {
    $options = new DumpOptions(
        database: 'test_db',
        schemaOnly: true,
    );

    $command = $this->dumpService->buildCommand($this->server, $options);

    expect($command)->toContain('--no-data');
});

test('build command includes data-only flag', function () {
    $options = new DumpOptions(
        database: 'test_db',
        dataOnly: true,
    );

    $command = $this->dumpService->buildCommand($this->server, $options);

    expect($command)->toContain('--no-create-info');
});

test('build command includes drop-tables flag', function () {
    $options = new DumpOptions(
        database: 'test_db',
        dropTables: true,
    );

    $command = $this->dumpService->buildCommand($this->server, $options);

    expect($command)->toContain('--add-drop-table');
});

test('build command includes standard flags', function () {
    $options = new DumpOptions(database: 'test_db');

    $command = $this->dumpService->buildCommand($this->server, $options);

    expect($command)->toContain('--single-transaction')
        ->and($command)->toContain('--routines')
        ->and($command)->toContain('--triggers')
        ->and($command)->toContain('--set-gtid-purged=OFF');
});

test('build command includes specific tables', function () {
    $options = new DumpOptions(
        database: 'test_db',
        tables: ['users', 'posts'],
    );

    $command = $this->dumpService->buildCommand($this->server, $options);

    expect($command)->toContain('\'users\'')
        ->and($command)->toContain('\'posts\'');
});

test('build command uses connection override for SSH tunnel', function () {
    $options = new DumpOptions(database: 'test_db');
    $connectionOverride = [
        'host' => '127.0.0.1',
        'port' => 33060,
    ];

    $command = $this->dumpService->buildCommand($this->server, $options, $connectionOverride);

    expect($command)->toContain('--host=\'127.0.0.1\'')
        ->and($command)->toContain('--port=33060');
});

test('generate output path includes database name and timestamp', function () {
    $path = $this->dumpService->generateOutputPath('test_db', false);

    expect($path)->toContain('test_db_')
        ->and($path)->toEndWith('.sql');
});

test('generate output path includes gzip extension', function () {
    $path = $this->dumpService->generateOutputPath('test_db', true);

    expect($path)->toContain('test_db_')
        ->and($path)->toEndWith('.sql.gz');
});

test('generate output path includes timestamp in correct format', function () {
    $path = $this->dumpService->generateOutputPath('test_db', false);

    // Extract timestamp part (format: YYYY-MM-DD_HHmmss)
    preg_match('/test_db_(\d{4}-\d{2}-\d{2}_\d{6})\.sql/', $path, $matches);

    expect($matches)->toHaveCount(2)
        ->and($matches[1])->toMatch('/\d{4}-\d{2}-\d{2}_\d{6}/');
});

test('check mysqldump exists returns boolean', function () {
    $result = $this->dumpService->checkMysqldumpExists();

    expect($result)->toBeBool();
});

test('dump executes command with spinner progress indication', function () {
    // Test that verifies dump() uses a spinner for progress indication
    // Since spin() from Laravel Prompts is hard to mock, we verify that
    // the dump operation completes successfully with the spinner wrapper

    $options = new DumpOptions(
        database: 'test_db',
        outputPath: sys_get_temp_dir() . '/test_dump_spinner.sql'
    );

    // Mock SSH tunnel service
    $this->sshTunnelService->shouldReceive('createTunnel')->never();
    $this->sshTunnelService->shouldReceive('closeTunnel')->never();

    $result = $this->dumpService->dump($this->server, $options);

    // Clean up
    if (file_exists(sys_get_temp_dir() . '/test_dump_spinner.sql')) {
        unlink(sys_get_temp_dir() . '/test_dump_spinner.sql');
    }

    // Verify dump completes (with or without spinner, the result should be valid)
    expect($result)->toBeInstanceOf(\App\ValueObjects\DumpResult::class)
        ->and($result->filePath)->toContain('test_dump_spinner.sql');
})->skip('Integration test - requires actual MySQL connection');
