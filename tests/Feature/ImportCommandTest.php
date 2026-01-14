<?php

use App\Models\Server;

beforeEach(function () {
    // Create test server
    Server::create([
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
    if (file_exists($this->tempFile)) {
        unlink($this->tempFile);
    }
});

test('import command requires server option', function () {
    $this->artisan('import --database=test_db --file='.$this->tempFile)
        ->expectsOutput('Server name is required. Use --server option.')
        ->assertExitCode(1);
});

test('import command requires database option', function () {
    $this->artisan('import --server=test-server --file='.$this->tempFile)
        ->expectsOutput('Database name is required. Use --database option.')
        ->assertExitCode(1);
});

test('import command requires file option', function () {
    $this->artisan('import --server=test-server --database=test_db')
        ->expectsOutput('File path is required. Use --file option.')
        ->assertExitCode(1);
});

test('import command validates server exists', function () {
    $this->artisan('import --server=nonexistent --database=test_db --file='.$this->tempFile)
        ->expectsOutputToContain("Server 'nonexistent' not found.")
        ->assertExitCode(1);
});

test('import command validates file exists', function () {
    // File validation happens before connection test in direct mode
    $this->artisan('import --server=test-server --database=test_db --file=/nonexistent/file.sql')
        ->expectsOutputToContain('File does not exist')
        ->assertExitCode(1);
});

test('import command validates file extension', function () {
    $txtFile = tempnam(sys_get_temp_dir(), 'test') . '.txt';
    file_put_contents($txtFile, 'SELECT 1;');

    $this->artisan('import --server=test-server --database=test_db --file='.$txtFile)
        ->expectsOutputToContain('Invalid file extension')
        ->assertExitCode(1);

    unlink($txtFile);
});

test('import command validates file is not empty', function () {
    $emptyFile = tempnam(sys_get_temp_dir(), 'test') . '.sql';
    touch($emptyFile);

    $this->artisan('import --server=test-server --database=test_db --file='.$emptyFile)
        ->expectsOutputToContain('File is empty')
        ->assertExitCode(1);

    unlink($emptyFile);
});

test('import command accepts .sql.gz files', function () {
    $gzFile = tempnam(sys_get_temp_dir(), 'test') . '.sql.gz';
    file_put_contents($gzFile, 'dummy gzip content');

    // This will fail at connection test, but validates file extension is accepted
    $this->artisan('import --server=test-server --database=test_db --file='.$gzFile)
        ->expectsOutputToContain('Testing connection')
        ->assertExitCode(1);

    unlink($gzFile);
});
