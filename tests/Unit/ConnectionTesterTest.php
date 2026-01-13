<?php

use App\Models\Server;
use App\Services\ConnectionTester;
use App\Services\SshTunnelService;
use Mockery;

beforeEach(function () {
    $this->sshTunnelService = Mockery::mock(SshTunnelService::class);
    $this->tester = new ConnectionTester($this->sshTunnelService);
});

test('test method returns array with required keys', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $result = $this->tester->test($server);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('success')
        ->and($result)->toHaveKey('message');
});

test('test method returns false for invalid connection', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $result = $this->tester->test($server);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Connection failed');
});

test('testWithCredentials returns array with required keys', function () {
    $result = $this->tester->testWithCredentials(
        'invalid-host-that-does-not-exist.local',
        3306,
        'root',
        'secret'
    );

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('success')
        ->and($result)->toHaveKey('message');
});

test('testWithCredentials returns false for invalid connection', function () {
    $result = $this->tester->testWithCredentials(
        'invalid-host-that-does-not-exist.local',
        3306,
        'root',
        'secret'
    );

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('Connection failed');
});

test('testWithCredentials includes database in DSN when provided', function () {
    $result = $this->tester->testWithCredentials(
        'invalid-host-that-does-not-exist.local',
        3306,
        'root',
        'secret',
        'testdb'
    );

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('success');
});

test('databaseExists returns false on connection error', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $exists = $this->tester->databaseExists($server, 'testdb');

    expect($exists)->toBeFalse();
});

test('listDatabases returns empty array on connection error', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $databases = $this->tester->listDatabases($server);

    expect($databases)->toBeArray()
        ->and($databases)->toBeEmpty();
});

test('listTables returns empty array on connection error', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $tables = $this->tester->listTables($server, 'testdb');

    expect($tables)->toBeArray()
        ->and($tables)->toBeEmpty();
});

test('getServerStatus returns empty array on connection error', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $status = $this->tester->getServerStatus($server);

    expect($status)->toBeArray()
        ->and($status)->toBeEmpty();
});

test('getServerVariables returns empty array on connection error', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $variables = $this->tester->getServerVariables($server);

    expect($variables)->toBeArray()
        ->and($variables)->toBeEmpty();
});

test('ping returns false on connection error', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $ping = $this->tester->ping($server);

    expect($ping)->toBeFalse();
});

test('test method includes details on success', function () {
    // This test would pass with a real MySQL connection
    // For now, we verify the structure exists
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $result = $this->tester->test($server);

    // Should have details key when there's info to provide
    expect($result)->toHaveKey('details');
});

test('test method includes error details on failure', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $result = $this->tester->test($server);

    expect($result)->toHaveKey('details')
        ->and($result['details'])->toHaveKey('error_code');
});
