<?php

use App\ValueObjects\ImportResult;

test('creates successful import result', function () {
    $result = ImportResult::success(
        database: 'test_db',
        filePath: '/path/to/dump.sql',
        duration: 1.5
    );

    expect($result->success)->toBe(true)
        ->and($result->database)->toBe('test_db')
        ->and($result->filePath)->toBe('/path/to/dump.sql')
        ->and($result->duration)->toBe(1.5)
        ->and($result->error)->toBe(null);
});

test('creates failed import result', function () {
    $result = ImportResult::failure(
        error: 'Connection failed',
        database: 'test_db',
        filePath: '/path/to/dump.sql'
    );

    expect($result->success)->toBe(false)
        ->and($result->database)->toBe('test_db')
        ->and($result->filePath)->toBe('/path/to/dump.sql')
        ->and($result->duration)->toBe(0.0)
        ->and($result->error)->toBe('Connection failed');
});

test('creates failed import result with minimal info', function () {
    $result = ImportResult::failure(error: 'File not found');

    expect($result->success)->toBe(false)
        ->and($result->database)->toBe('')
        ->and($result->filePath)->toBe('')
        ->and($result->duration)->toBe(0.0)
        ->and($result->error)->toBe('File not found');
});

test('success result has no error', function () {
    $result = ImportResult::success(
        database: 'test_db',
        filePath: '/path/to/dump.sql',
        duration: 2.0
    );

    expect($result->error)->toBeNull();
});

test('failure result has error message', function () {
    $result = ImportResult::failure(error: 'Import failed');

    expect($result->error)->toBe('Import failed');
});
