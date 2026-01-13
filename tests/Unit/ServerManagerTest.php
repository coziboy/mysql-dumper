<?php

use App\Models\Server;
use App\Services\ServerManager;

beforeEach(function () {
    $this->manager = new ServerManager;
});

test('can get all servers', function () {
    Server::create([
        'name' => 'server-1',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    Server::create([
        'name' => 'server-2',
        'host' => 'localhost',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $servers = $this->manager->all();

    expect($servers)->toHaveCount(2);
});

test('can find server by name', function () {
    Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $server = $this->manager->findByName('test-server');

    expect($server)->not->toBeNull()
        ->and($server->name)->toBe('test-server');
});

test('returns null when server name not found', function () {
    $server = $this->manager->findByName('non-existent');

    expect($server)->toBeNull();
});

test('can find server by id', function () {
    $created = Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $server = $this->manager->findById($created->id);

    expect($server)->not->toBeNull()
        ->and($server->id)->toBe($created->id);
});

test('can get default server', function () {
    Server::create([
        'name' => 'server-1',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    Server::create([
        'name' => 'default-server',
        'host' => 'localhost',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
        'is_default' => true,
    ]);

    $default = $this->manager->getDefault();

    expect($default)->not->toBeNull()
        ->and($default->name)->toBe('default-server');
});

test('can create server', function () {
    $server = $this->manager->create([
        'name' => 'new-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    expect($server)->toBeInstanceOf(Server::class)
        ->and($server->name)->toBe('new-server')
        ->and($server->exists)->toBeTrue();
});

test('can update server', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $updated = $this->manager->update($server, [
        'host' => '192.168.1.100',
        'port' => 3307,
    ]);

    expect($updated)->toBeTrue();

    $server->refresh();

    expect($server->host)->toBe('192.168.1.100')
        ->and($server->port)->toBe(3307);
});

test('can delete server', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $deleted = $this->manager->delete($server);

    expect($deleted)->toBeTrue()
        ->and(Server::find($server->id))->toBeNull();
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

    $result = $this->manager->setDefault($server2);

    expect($result)->toBeTrue();

    $server1->refresh();
    $server2->refresh();

    expect($server1->is_default)->toBeFalse()
        ->and($server2->is_default)->toBeTrue();
});

test('can check if server name exists', function () {
    Server::create([
        'name' => 'existing-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    expect($this->manager->exists('existing-server'))->toBeTrue()
        ->and($this->manager->exists('non-existent'))->toBeFalse();
});

test('can get server count', function () {
    Server::create([
        'name' => 'server-1',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    Server::create([
        'name' => 'server-2',
        'host' => 'localhost',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
    ]);

    expect($this->manager->count())->toBe(2);
});

test('can get server names', function () {
    Server::create([
        'name' => 'server-1',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    Server::create([
        'name' => 'server-2',
        'host' => 'localhost',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $names = $this->manager->getNames();

    expect($names)->toHaveCount(2)
        ->and($names->toArray())->toContain('server-1', 'server-2');
});

test('can get name id pairs', function () {
    $server1 = Server::create([
        'name' => 'server-1',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $server2 = Server::create([
        'name' => 'server-2',
        'host' => 'localhost',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
    ]);

    $pairs = $this->manager->getNameIdPairs();

    expect($pairs)->toHaveCount(2)
        ->and($pairs['server-1'])->toBe($server1->id)
        ->and($pairs['server-2'])->toBe($server2->id);
});

test('validates required fields', function () {
    $errors = $this->manager->validate([]);

    expect($errors)->toHaveKey('name')
        ->and($errors)->toHaveKey('host')
        ->and($errors)->toHaveKey('username')
        ->and($errors)->toHaveKey('password');
});

test('validates port range', function () {
    $errors1 = $this->manager->validate([
        'name' => 'test',
        'host' => 'localhost',
        'username' => 'root',
        'password' => 'secret',
        'port' => 0,
    ]);

    expect($errors1)->toHaveKey('port');

    $errors2 = $this->manager->validate([
        'name' => 'test',
        'host' => 'localhost',
        'username' => 'root',
        'password' => 'secret',
        'port' => 70000,
    ]);

    expect($errors2)->toHaveKey('port');
});

test('validates ssh configuration', function () {
    $errors = $this->manager->validate([
        'name' => 'test',
        'host' => 'localhost',
        'username' => 'root',
        'password' => 'secret',
        'ssh_host' => 'bastion.example.com',
    ]);

    expect($errors)->toHaveKey('ssh_username')
        ->and($errors)->toHaveKey('ssh_auth');
});

test('can export server without sensitive data', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'database' => 'testdb',
    ]);

    $exported = $this->manager->export($server);

    expect($exported)->toHaveKey('name')
        ->and($exported)->toHaveKey('host')
        ->and($exported)->not->toHaveKey('password');
});

test('can export server with sensitive data', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'database' => 'testdb',
    ]);

    $exported = $this->manager->export($server, true);

    expect($exported)->toHaveKey('name')
        ->and($exported)->toHaveKey('host')
        ->and($exported)->toHaveKey('password')
        ->and($exported['password'])->toBe('secret');
});

test('export includes ssh configuration when present', function () {
    $server = Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'ssh_host' => 'bastion.example.com',
        'ssh_username' => 'deploy',
        'ssh_password' => 'ssh-secret',
    ]);

    $exported = $this->manager->export($server, true);

    expect($exported)->toHaveKey('ssh_host')
        ->and($exported)->toHaveKey('ssh_username')
        ->and($exported)->toHaveKey('ssh_password');
});

test('can duplicate server', function () {
    $original = Server::create([
        'name' => 'original-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'database' => 'testdb',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'is_default' => true,
    ]);

    $duplicate = $this->manager->duplicate($original, 'duplicate-server');

    expect($duplicate)->toBeInstanceOf(Server::class)
        ->and($duplicate->name)->toBe('duplicate-server')
        ->and($duplicate->host)->toBe('localhost')
        ->and($duplicate->password)->toBe('secret')
        ->and($duplicate->charset)->toBe('utf8mb4')
        ->and($duplicate->is_default)->toBeFalse()
        ->and($duplicate->id)->not->toBe($original->id);
});

test('can import valid server configuration', function () {
    $config = [
        'name' => 'imported-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
    ];

    $server = $this->manager->import($config);

    expect($server)->toBeInstanceOf(Server::class)
        ->and($server->name)->toBe('imported-server')
        ->and($server->exists)->toBeTrue();
});

test('import throws exception on invalid configuration', function () {
    $this->manager->import([
        'name' => 'invalid-server',
    ]);
})->throws(\InvalidArgumentException::class);
