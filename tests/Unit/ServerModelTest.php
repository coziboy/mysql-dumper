<?php

use App\Models\Server;

test('server can be created with basic attributes', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    expect($server->name)->toBe('test-server')
        ->and($server->host)->toBe('localhost')
        ->and($server->port)->toBe(3306)
        ->and($server->username)->toBe('root');
});

test('password is encrypted in database', function () {
    $server = Server::create([
        'name' => 'encrypted-test',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'my-secret-password',
    ]);

    // Refresh to get raw database value
    $rawServer = Server::query()->find($server->id);

    // The password should be accessible normally
    expect($rawServer->password)->toBe('my-secret-password');

    // But the raw database value should be encrypted
    expect($rawServer->getAttributes()['password'])->not->toBe('my-secret-password')
        ->and($rawServer->getAttributes()['password'])->not->toBeNull();
});

test('ssh password is encrypted in database', function () {
    $server = Server::create([
        'name' => 'ssh-test',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'ssh_host' => 'bastion.example.com',
        'ssh_username' => 'deploy',
        'ssh_password' => 'ssh-secret',
    ]);

    $rawServer = Server::query()->find($server->id);

    // SSH password should be accessible normally
    expect($rawServer->ssh_password)->toBe('ssh-secret');

    // But raw value should be encrypted
    expect($rawServer->getAttributes()['ssh_password'])->not->toBe('ssh-secret')
        ->and($rawServer->getAttributes()['ssh_password'])->not->toBeNull();
});

test('can set server as default', function () {
    $server1 = Server::create([
        'name' => 'server-1',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'is_default' => true,
    ]);

    $server2 = Server::create([
        'name' => 'server-2',
        'host' => 'localhost',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
    ]);

    // Set server2 as default
    $server2->setAsDefault();

    // Refresh both servers
    $server1->refresh();
    $server2->refresh();

    expect($server1->is_default)->toBeFalse()
        ->and($server2->is_default)->toBeTrue();
});

test('default scope returns only default server', function () {
    Server::create([
        'name' => 'non-default',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'is_default' => false,
    ]);

    $defaultServer = Server::create([
        'name' => 'default-server',
        'host' => 'localhost',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
        'is_default' => true,
    ]);

    $result = Server::default()->first();

    expect($result->id)->toBe($defaultServer->id)
        ->and($result->name)->toBe('default-server');
});

test('getDsn returns correct DSN string', function () {
    $server = Server::create([
        'name' => 'dsn-test',
        'host' => '192.168.1.100',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
        'database' => 'mydb',
        'charset' => 'utf8mb4',
    ]);

    $dsn = $server->getDsn();

    expect($dsn)->toBe('mysql:host=192.168.1.100;port=3307;dbname=mydb;charset=utf8mb4');
});

test('getDsn works without database', function () {
    $server = Server::create([
        'name' => 'no-db-test',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
    ]);

    $dsn = $server->getDsn();

    expect($dsn)->toBe('mysql:host=localhost;port=3306;charset=utf8mb4');
});

test('hasSshTunnel returns true when ssh_host is set', function () {
    $server = Server::create([
        'name' => 'ssh-tunnel-test',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'ssh_host' => 'bastion.example.com',
    ]);

    expect($server->hasSshTunnel())->toBeTrue();
});

test('hasSshTunnel returns false when ssh_host is not set', function () {
    $server = Server::create([
        'name' => 'no-ssh-test',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    expect($server->hasSshTunnel())->toBeFalse();
});

test('getSshOptions returns array when SSH is configured', function () {
    $server = Server::create([
        'name' => 'ssh-options-test',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'ssh_host' => 'bastion.example.com',
        'ssh_port' => 2222,
        'ssh_username' => 'deploy',
        'ssh_password' => 'ssh-pass',
        'ssh_key_path' => '/home/user/.ssh/id_rsa',
    ]);

    $options = $server->getSshOptions();

    expect($options)->toBeArray()
        ->and($options['host'])->toBe('bastion.example.com')
        ->and($options['port'])->toBe(2222)
        ->and($options['username'])->toBe('deploy')
        ->and($options['password'])->toBe('ssh-pass')
        ->and($options['key_path'])->toBe('/home/user/.ssh/id_rsa');
});

test('getSshOptions returns null when SSH is not configured', function () {
    $server = Server::create([
        'name' => 'no-ssh-options-test',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    expect($server->getSshOptions())->toBeNull();
});

test('getConnectionOptions returns correct array', function () {
    $server = Server::create([
        'name' => 'connection-test',
        'host' => '192.168.1.100',
        'port' => 3307,
        'username' => 'dbuser',
        'password' => 'dbpass',
        'database' => 'testdb',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'options' => ['timeout' => 30],
    ]);

    $options = $server->getConnectionOptions();

    expect($options['host'])->toBe('192.168.1.100')
        ->and($options['port'])->toBe(3307)
        ->and($options['username'])->toBe('dbuser')
        ->and($options['password'])->toBe('dbpass')
        ->and($options['database'])->toBe('testdb')
        ->and($options['charset'])->toBe('utf8mb4')
        ->and($options['collation'])->toBe('utf8mb4_unicode_ci')
        ->and($options['timeout'])->toBe(30);
});

test('options are cast to array', function () {
    $server = Server::create([
        'name' => 'options-cast-test',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'options' => ['key' => 'value', 'timeout' => 60],
    ]);

    $server->refresh();

    expect($server->options)->toBeArray()
        ->and($server->options['key'])->toBe('value')
        ->and($server->options['timeout'])->toBe(60);
});

test('sensitive fields are hidden in array representation', function () {
    $server = Server::create([
        'name' => 'hidden-test',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'ssh_password' => 'ssh-secret',
    ]);

    $array = $server->toArray();

    expect($array)->not->toHaveKey('password')
        ->and($array)->not->toHaveKey('ssh_password')
        ->and($array)->toHaveKey('name')
        ->and($array)->toHaveKey('host');
});
