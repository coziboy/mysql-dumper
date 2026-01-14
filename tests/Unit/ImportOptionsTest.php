<?php

use App\ValueObjects\ImportOptions;

test('creates valid import options', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.sql';
    file_put_contents($tempFile, 'SELECT 1;');

    $options = new ImportOptions(
        database: 'test_db',
        filePath: $tempFile,
    );

    expect($options->database)->toBe('test_db')
        ->and($options->filePath)->toBe($tempFile)
        ->and($options->forceImport)->toBe(false);

    unlink($tempFile);
});

test('validates database name is not empty', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.sql';
    file_put_contents($tempFile, 'SELECT 1;');

    expect(fn () => new ImportOptions(
        database: '',
        filePath: $tempFile,
    ))->toThrow(InvalidArgumentException::class, 'Database name cannot be empty.');

    unlink($tempFile);
});

test('validates file path is not empty', function () {
    expect(fn () => new ImportOptions(
        database: 'test_db',
        filePath: '',
    ))->toThrow(InvalidArgumentException::class, 'File path cannot be empty.');
});

test('validates file exists', function () {
    expect(fn () => new ImportOptions(
        database: 'test_db',
        filePath: '/nonexistent/file.sql',
    ))->toThrow(InvalidArgumentException::class, 'File does not exist');
});

test('validates file is readable', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.sql';
    file_put_contents($tempFile, 'SELECT 1;');
    chmod($tempFile, 0000);

    try {
        expect(fn () => new ImportOptions(
            database: 'test_db',
            filePath: $tempFile,
        ))->toThrow(InvalidArgumentException::class, 'File is not readable');
    } finally {
        chmod($tempFile, 0644);
        unlink($tempFile);
    }
});

test('validates file extension for .sql files', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.sql';
    file_put_contents($tempFile, 'SELECT 1;');

    $options = new ImportOptions(
        database: 'test_db',
        filePath: $tempFile,
    );

    expect($options->filePath)->toBe($tempFile);

    unlink($tempFile);
});

test('validates file extension for .sql.gz files', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.sql.gz';
    file_put_contents($tempFile, 'dummy gzip content');

    $options = new ImportOptions(
        database: 'test_db',
        filePath: $tempFile,
    );

    expect($options->filePath)->toBe($tempFile);

    unlink($tempFile);
});

test('rejects invalid file extensions', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.txt';
    file_put_contents($tempFile, 'SELECT 1;');

    expect(fn () => new ImportOptions(
        database: 'test_db',
        filePath: $tempFile,
    ))->toThrow(InvalidArgumentException::class, 'Invalid file extension');

    unlink($tempFile);
});

test('validates file is not empty', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.sql';
    touch($tempFile);

    expect(fn () => new ImportOptions(
        database: 'test_db',
        filePath: $tempFile,
    ))->toThrow(InvalidArgumentException::class, 'File is empty');

    unlink($tempFile);
});

test('accepts forceImport flag', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'test') . '.sql';
    file_put_contents($tempFile, 'SELECT 1;');

    $options = new ImportOptions(
        database: 'test_db',
        filePath: $tempFile,
        forceImport: true,
    );

    expect($options->forceImport)->toBe(true);

    unlink($tempFile);
});
