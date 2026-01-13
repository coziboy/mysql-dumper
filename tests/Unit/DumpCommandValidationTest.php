<?php

use App\Models\Server;
use App\Services\ConnectionTester;
use App\Services\DumpService;

beforeEach(function () {
    Server::create([
        'name' => 'test-server',
        'host' => 'localhost',
        'port' => 3306,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);
});

test('dump command rejects invalid table names with special characters', function () {
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => true, 'message' => 'Connected']);
    $connectionTester->shouldReceive('listDatabases')
        ->andReturn(['test_db']);
    $connectionTester->shouldReceive('listTables')
        ->andReturn(['users', 'posts']);

    $this->app->instance(ConnectionTester::class, $connectionTester);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
        '--tables' => 'users; DROP TABLE posts;',
    ])
        ->expectsOutputToContain('Invalid table names detected')
        ->assertExitCode(1);

    Mockery::close();
});

test('dump command fails when database does not exist', function () {
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => true, 'message' => 'Connected']);
    $connectionTester->shouldReceive('listDatabases')
        ->andReturn(['other_db', 'another_db']);

    $this->app->instance(ConnectionTester::class, $connectionTester);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'nonexistent_db',
    ])
        ->expectsOutputToContain('Database \'nonexistent_db\' not found')
        ->expectsOutputToContain('Available databases:')
        ->assertExitCode(1);

    Mockery::close();
});

test('dump command fails when specified tables do not exist', function () {
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => true, 'message' => 'Connected']);
    $connectionTester->shouldReceive('listDatabases')
        ->andReturn(['test_db']);
    $connectionTester->shouldReceive('listTables')
        ->with(Mockery::type(Server::class), 'test_db')
        ->andReturn(['users', 'posts']);

    $this->app->instance(ConnectionTester::class, $connectionTester);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
        '--tables' => 'users,nonexistent_table',
    ])
        ->expectsOutputToContain('do not exist in database')
        ->expectsOutputToContain('nonexistent_table')
        ->expectsOutputToContain('Available tables:')
        ->assertExitCode(1);

    Mockery::close();
});

test('dump command fails with non-writable output directory', function () {
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => true, 'message' => 'Connected']);
    $connectionTester->shouldReceive('listDatabases')
        ->andReturn(['test_db']);

    $this->app->instance(ConnectionTester::class, $connectionTester);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
        '--output' => '/root/restricted/dump.sql',
    ])
        ->assertExitCode(1);

    Mockery::close();
});

test('dump command validates empty table list correctly', function () {
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => true, 'message' => 'Connected']);
    $connectionTester->shouldReceive('listDatabases')
        ->andReturn(['test_db']);

    $dumpService = Mockery::mock(DumpService::class);
    $dumpService->shouldReceive('dump')
        ->once()
        ->with(
            Mockery::type(Server::class),
            Mockery::on(function ($options) {
                // Empty tables option should result in empty array (dump all tables)
                return $options->tables === [];
            })
        )
        ->andReturn(\App\ValueObjects\DumpResult::success('/tmp/dump.sql', 1024, 1.0));

    $this->app->instance(ConnectionTester::class, $connectionTester);
    $this->app->instance(DumpService::class, $dumpService);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
        '--tables' => '',
    ])
        ->assertExitCode(0);

    Mockery::close();
});

test('dump command shows helpful suggestions on connection failure', function () {
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => false, 'message' => 'Connection refused']);

    $this->app->instance(ConnectionTester::class, $connectionTester);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
    ])
        ->expectsOutputToContain('Connection failed')
        ->expectsOutputToContain('Troubleshooting tips:')
        ->expectsOutputToContain('Verify server credentials')
        ->assertExitCode(1);

    Mockery::close();
});

test('dump command lists available servers when server not found', function () {
    // Add another server for testing
    Server::create([
        'name' => 'another-server',
        'host' => 'localhost',
        'port' => 3307,
        'username' => 'root',
        'password' => 'secret',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);

    $this->artisan('dump', [
        '--server' => 'nonexistent-server',
        '--database' => 'test_db',
    ])
        ->expectsOutputToContain('Server \'nonexistent-server\' not found')
        ->expectsOutputToContain('Available servers:')
        ->assertExitCode(1);
});
