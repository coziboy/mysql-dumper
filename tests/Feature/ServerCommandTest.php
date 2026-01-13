<?php

use App\Models\Server;
use Laravel\Prompts\Prompt;

test('server list command shows no servers message when empty', function () {
    $this->artisan('server list')
        ->expectsOutput('No servers configured.')
        ->assertSuccessful();
});

test('server list command displays servers table', function () {
    Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'database' => 'testdb',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $this->artisan('server list')
        ->expectsTable(
            ['ID', 'Name', 'Host', 'Port', 'Username', 'Database', 'Default'],
            [
                [1, 'test-server', 'localhost', 3306, 'root', 'testdb', ''],
            ]
        )
        ->assertSuccessful();
});

test('server list command shows default indicator', function () {
    Server::create([
        'name' => 'default-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'is_default' => true,
    ]);

    $this->artisan('server list')
        ->expectsTable(
            ['ID', 'Name', 'Host', 'Port', 'Username', 'Database', 'Default'],
            [
                [1, 'default-server', 'localhost', 3306, 'root', '-', '✓'],
            ]
        )
        ->assertSuccessful();
});

test('server test command with non-existent server', function () {
    $this->artisan('server test non-existent')
        ->expectsOutput("Server 'non-existent' not found.")
        ->assertFailed();
});

test('server test command with invalid server', function () {
    Server::create([
        'name' => 'invalid-server',
        'host' => 'invalid-host-that-does-not-exist.local',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $this->artisan('server test invalid-server')
        ->expectsOutput("Testing connection to 'invalid-server'...")
        ->assertFailed();
});

test('server delete command with non-existent server', function () {
    $this->artisan('server delete non-existent')
        ->expectsOutput("Server 'non-existent' not found.")
        ->assertFailed();
});

test('server delete command with confirmation', function () {
    Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    Prompt::fake([true]); // Confirm deletion

    $this->artisan('server delete test-server')
        ->expectsOutput("Server 'test-server' deleted successfully!")
        ->assertSuccessful();

    expect(Server::where('name', 'test-server')->exists())->toBeFalse();
})->skip('Laravel Prompts testing needs further configuration');

test('server delete command cancelled', function () {
    Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    Prompt::fake([false]); // Cancel deletion

    $this->artisan('server delete test-server')
        ->expectsOutput('Deletion cancelled.')
        ->assertSuccessful();

    expect(Server::where('name', 'test-server')->exists())->toBeTrue();
})->skip('Laravel Prompts testing needs further configuration');

test('server default command with non-existent server', function () {
    $this->artisan('server default non-existent')
        ->expectsOutput("Server 'non-existent' not found.")
        ->assertFailed();
});

test('server default command sets default server', function () {
    Server::create([
        'name' => 'server-1',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'is_default' => true,
    ]);

    $server2 = Server::create([
        'name' => 'server-2',
        'host' => 'localhost',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $this->artisan('server default server-2')
        ->expectsOutput("'server-2' is now the default server.")
        ->assertSuccessful();

    $server2->refresh();
    expect($server2->is_default)->toBeTrue();
});

test('server command with invalid action', function () {
    $this->artisan('server invalid-action')
        ->expectsOutput('Unknown action: invalid-action')
        ->assertFailed();
});

test('server add command validates required fields', function () {
    Prompt::fake([
        '',          // Server name
        'localhost', // Host
        '3306',      // Port
        'root',      // Username
        '',          // Password
        '',          // Database (optional)
        'utf8mb4',   // Charset
        'utf8mb4_unicode_ci', // Collation
        false,       // Use SSH tunnel?
    ]);

    $this->artisan('server add')
        ->expectsOutput('Validation failed:')
        ->assertFailed();
})->skip('Laravel Prompts testing needs further configuration');

test('server add command creates server successfully', function () {
    Prompt::fake([
        'new-server', // Server name
        'localhost',  // Host
        '3306',       // Port
        'root',       // Username
        'secret',     // Password
        'testdb',     // Database (optional)
        'utf8mb4',    // Charset
        'utf8mb4_unicode_ci', // Collation
        false,        // Use SSH tunnel?
    ]);

    $this->artisan('server add')
        ->expectsOutput("Server 'new-server' created successfully!")
        ->expectsOutput('Set as default server.')
        ->assertSuccessful();

    $server = Server::where('name', 'new-server')->first();
    expect($server)->not->toBeNull()
        ->and($server->host)->toBe('localhost')
        ->and($server->is_default)->toBeTrue();
})->skip('Laravel Prompts testing needs further configuration');

test('server add command with ssh tunnel password authentication', function () {
    // Create a first server so the new one will ask about default
    Server::create([
        'name' => 'existing',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    Prompt::fake([
        'ssh-server',            // Server name
        'localhost',             // Host
        '3306',                  // Port
        'root',                  // Username
        'secret',                // Password
        '',                      // Database (optional)
        'utf8mb4',               // Charset
        'utf8mb4_unicode_ci',    // Collation
        true,                    // Use SSH tunnel?
        'bastion.example.com',   // SSH Host
        '22',                    // SSH Port
        'deploy',                // SSH Username
        'password',              // SSH Authentication (select)
        'ssh-secret',            // SSH Password
        false,                   // Set as default server?
    ]);

    $this->artisan('server add')
        ->expectsOutput("Server 'ssh-server' created successfully!")
        ->assertSuccessful();

    $server = Server::where('name', 'ssh-server')->first();
    expect($server)->not->toBeNull()
        ->and($server->ssh_host)->toBe('bastion.example.com')
        ->and($server->ssh_password)->toBe('ssh-secret');
})->skip('Laravel Prompts testing needs further configuration');

test('server add command with ssh tunnel key authentication', function () {
    // Create a first server so the new one will ask about default
    Server::create([
        'name' => 'existing',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    Prompt::fake([
        'ssh-key-server',        // Server name
        'localhost',             // Host
        '3306',                  // Port
        'root',                  // Username
        'secret',                // Password
        '',                      // Database (optional)
        'utf8mb4',               // Charset
        'utf8mb4_unicode_ci',    // Collation
        true,                    // Use SSH tunnel?
        'bastion.example.com',   // SSH Host
        '22',                    // SSH Port
        'deploy',                // SSH Username
        'key',                   // SSH Authentication (select)
        '~/.ssh/id_rsa',         // SSH Key Path
        false,                   // Set as default server?
    ]);

    $this->artisan('server add')
        ->expectsOutput("Server 'ssh-key-server' created successfully!")
        ->assertSuccessful();

    $server = Server::where('name', 'ssh-key-server')->first();
    expect($server)->not->toBeNull()
        ->and($server->ssh_host)->toBe('bastion.example.com')
        ->and($server->ssh_key_path)->toBe('~/.ssh/id_rsa');
})->skip('Laravel Prompts testing needs further configuration');

test('server edit command updates server', function () {
    Server::create([
        'name' => 'edit-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    Prompt::fake([
        'edit-server',           // Server name
        '192.168.1.100',         // Host
        '3307',                  // Port
        'dbuser',                // Username
        'mydb',                  // Database
        'utf8mb4',               // Charset
        'utf8mb4_unicode_ci',    // Collation
        false,                   // Update password?
    ]);

    $this->artisan('server edit edit-server')
        ->expectsOutput('Edit Server: edit-server')
        ->expectsOutput("Server 'edit-server' updated successfully!")
        ->assertSuccessful();

    $server = Server::where('name', 'edit-server')->first();
    expect($server->host)->toBe('192.168.1.100')
        ->and($server->port)->toBe(3307)
        ->and($server->username)->toBe('dbuser');
})->skip('Laravel Prompts testing needs further configuration');
