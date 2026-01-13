<?php

use App\Models\Server;
use App\Services\ConnectionTester;
use App\Services\DumpService;
use App\ValueObjects\DumpResult;

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

test('dump command is registered', function () {
    $this->artisan('list')
        ->expectsOutputToContain('dump')
        ->assertExitCode(0);
});

test('dump command requires server option in direct mode', function () {
    $this->artisan('dump', ['--database' => 'test_db'])
        ->expectsOutputToContain('Server name is required')
        ->assertExitCode(1);
});

test('dump command requires database option in direct mode', function () {
    $this->artisan('dump', ['--server' => 'test-server'])
        ->expectsOutputToContain('Database name is required')
        ->assertExitCode(1);
});

test('dump command fails when server not found', function () {
    $this->artisan('dump', [
        '--server' => 'nonexistent-server',
        '--database' => 'test_db',
    ])
        ->expectsOutputToContain('not found')
        ->assertExitCode(1);
});

test('dump command fails when both schema-only and data-only flags are set', function () {
    // Mock connection tester to pass connection test
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => true, 'message' => 'Connected']);
    $connectionTester->shouldReceive('listDatabases')
        ->andReturn(['test_db', 'other_db']);

    $this->app->instance(ConnectionTester::class, $connectionTester);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
        '--schema-only' => true,
        '--data-only' => true,
    ])
        ->expectsOutputToContain('Cannot use both --schema-only and --data-only')
        ->assertExitCode(1);

    Mockery::close();
});

test('dump command parses tables option correctly', function () {
    // Mock services
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => true, 'message' => 'Connected']);
    $connectionTester->shouldReceive('listDatabases')
        ->andReturn(['test_db', 'other_db']);
    $connectionTester->shouldReceive('listTables')
        ->with(Mockery::type(Server::class), 'test_db')
        ->andReturn(['users', 'posts', 'comments', 'logs']);

    $dumpService = Mockery::mock(DumpService::class);
    $dumpService->shouldReceive('dump')
        ->once()
        ->with(
            Mockery::type(Server::class),
            Mockery::on(function ($options) {
                return $options->tables === ['users', 'posts', 'comments'];
            })
        )
        ->andReturn(DumpResult::success('/tmp/dump.sql', 1024, 1.0));

    $this->app->instance(ConnectionTester::class, $connectionTester);
    $this->app->instance(DumpService::class, $dumpService);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
        '--tables' => 'users,posts,comments',
    ])
        ->assertExitCode(0);

    Mockery::close();
});

test('dump command handles successful dump', function () {
    // Mock services
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => true, 'message' => 'Connected']);
    $connectionTester->shouldReceive('listDatabases')
        ->andReturn(['test_db', 'other_db']);

    $dumpService = Mockery::mock(DumpService::class);
    $dumpService->shouldReceive('dump')
        ->once()
        ->andReturn(DumpResult::success('/tmp/test_db_dump.sql', 2048, 1.5));

    $this->app->instance(ConnectionTester::class, $connectionTester);
    $this->app->instance(DumpService::class, $dumpService);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
    ])
        ->expectsOutputToContain('Dump completed successfully')
        ->expectsOutputToContain('/tmp/test_db_dump.sql')
        ->expectsOutputToContain('2 KB')
        ->assertExitCode(0);

    Mockery::close();
});

test('dump command handles failed dump', function () {
    // Mock services
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => true, 'message' => 'Connected']);
    $connectionTester->shouldReceive('listDatabases')
        ->andReturn(['test_db', 'other_db']);

    $dumpService = Mockery::mock(DumpService::class);
    $dumpService->shouldReceive('dump')
        ->once()
        ->andReturn(DumpResult::failure('mysqldump command failed'));

    $this->app->instance(ConnectionTester::class, $connectionTester);
    $this->app->instance(DumpService::class, $dumpService);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
    ])
        ->assertFailed();

    Mockery::close();
});

test('dump command handles connection failure', function () {
    // Mock services
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => false, 'message' => 'Connection refused']);

    $this->app->instance(ConnectionTester::class, $connectionTester);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
    ])
        ->assertFailed();

    Mockery::close();
});

test('dump command with gzip flag', function () {
    // Mock services
    $connectionTester = Mockery::mock(ConnectionTester::class);
    $connectionTester->shouldReceive('test')
        ->andReturn(['success' => true, 'message' => 'Connected']);
    $connectionTester->shouldReceive('listDatabases')
        ->andReturn(['test_db', 'other_db']);

    $dumpService = Mockery::mock(DumpService::class);
    $dumpService->shouldReceive('dump')
        ->once()
        ->with(
            Mockery::type(Server::class),
            Mockery::on(function ($options) {
                return $options->gzip === true;
            })
        )
        ->andReturn(DumpResult::success('/tmp/test_db.sql.gz', 512, 1.0));

    $this->app->instance(ConnectionTester::class, $connectionTester);
    $this->app->instance(DumpService::class, $dumpService);

    $this->artisan('dump', [
        '--server' => 'test-server',
        '--database' => 'test_db',
        '--gzip' => true,
    ])
        ->assertExitCode(0);

    Mockery::close();
});

test('dump command with custom output path', function () {
    // Create temporary directory for test
    $tempDir = sys_get_temp_dir().'/mysql-dumper-test-'.uniqid();
    mkdir($tempDir, 0777, true);

    try {
        // Mock services
        $connectionTester = Mockery::mock(ConnectionTester::class);
        $connectionTester->shouldReceive('test')
            ->andReturn(['success' => true, 'message' => 'Connected']);
        $connectionTester->shouldReceive('listDatabases')
            ->andReturn(['test_db', 'other_db']);

        $dumpService = Mockery::mock(DumpService::class);
        $dumpService->shouldReceive('dump')
            ->once()
            ->with(
                Mockery::type(Server::class),
                Mockery::on(function ($options) use ($tempDir) {
                    return $options->outputPath === $tempDir.'/dump.sql';
                })
            )
            ->andReturn(DumpResult::success($tempDir.'/dump.sql', 1024, 1.0));

        $this->app->instance(ConnectionTester::class, $connectionTester);
        $this->app->instance(DumpService::class, $dumpService);

        $this->artisan('dump', [
            '--server' => 'test-server',
            '--database' => 'test_db',
            '--output' => $tempDir.'/dump.sql',
        ])
            ->assertExitCode(0);

        Mockery::close();
    } finally {
        // Cleanup
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }
    }
});
