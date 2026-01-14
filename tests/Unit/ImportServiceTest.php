<?php

use App\Models\Server;
use App\Services\ImportService;
use App\Services\SshTunnelService;
use App\ValueObjects\ImportOptions;

beforeEach(function () {
    $this->sshTunnelService = Mockery::mock(SshTunnelService::class);
    $this->importService = new ImportService($this->sshTunnelService);

    $this->server = Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    // Create a temporary SQL file for testing
    $this->tempFile = tempnam(sys_get_temp_dir(), 'test') . '.sql';
    file_put_contents($this->tempFile, 'SELECT 1;');
});

afterEach(function () {
    Mockery::close();
    if (file_exists($this->tempFile)) {
        unlink($this->tempFile);
    }
});

test('build command includes basic connection parameters', function () {
    $options = new ImportOptions(
        database: 'test_db',
        filePath: $this->tempFile
    );

    $command = $this->importService->buildCommand($this->server, $options);

    expect($command)->toContain('mysql')
        ->and($command)->toContain('--host=\'localhost\'')
        ->and($command)->toContain('--port=3306')
        ->and($command)->toContain('--user=\'root\'')
        ->and($command)->toContain('--password=\'secret\'')
        ->and($command)->toContain('\'test_db\'');
});

test('build command includes charset flag', function () {
    $options = new ImportOptions(
        database: 'test_db',
        filePath: $this->tempFile
    );

    $command = $this->importService->buildCommand($this->server, $options);

    expect($command)->toContain('--default-character-set=\'utf8mb4\'');
});

test('build command uses connection override for SSH tunnel', function () {
    $options = new ImportOptions(
        database: 'test_db',
        filePath: $this->tempFile
    );
    $connectionOverride = [
        'host' => '127.0.0.1',
        'port' => 33060,
    ];

    $command = $this->importService->buildCommand($this->server, $options, $connectionOverride);

    expect($command)->toContain('--host=\'127.0.0.1\'')
        ->and($command)->toContain('--port=33060');
});

test('check mysql exists returns boolean', function () {
    $result = $this->importService->checkMysqlExists();

    expect($result)->toBeBool();
});

test('detects gzipped file by extension', function () {
    $gzFile = tempnam(sys_get_temp_dir(), 'test') . '.sql.gz';
    file_put_contents($gzFile, 'dummy content');

    $result = $this->importService->isGzipped($gzFile);

    expect($result)->toBe(true);

    unlink($gzFile);
});

test('detects gzipped file by magic bytes', function () {
    $gzFile = tempnam(sys_get_temp_dir(), 'test') . '.sql';
    // Write gzip magic bytes (1f 8b)
    file_put_contents($gzFile, hex2bin('1f8b') . 'rest of content');

    $result = $this->importService->isGzipped($gzFile);

    expect($result)->toBe(true);

    unlink($gzFile);
});

test('detects non-gzipped file', function () {
    $result = $this->importService->isGzipped($this->tempFile);

    expect($result)->toBe(false);
});

test('handles non-existent file in gzip detection', function () {
    $result = $this->importService->isGzipped('/nonexistent/file.sql');

    expect($result)->toBe(false);
});

test('build command escapes special characters in database name', function () {
    $options = new ImportOptions(
        database: 'test-db',
        filePath: $this->tempFile
    );

    $command = $this->importService->buildCommand($this->server, $options);

    expect($command)->toContain('\'test-db\'');
});

test('build command works without password', function () {
    $server = Server::create([
        'name' => 'no-pass-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => null,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $options = new ImportOptions(
        database: 'test_db',
        filePath: $this->tempFile
    );

    $command = $this->importService->buildCommand($server, $options);

    expect($command)->toContain('mysql')
        ->and($command)->not->toContain('--password=');
});
