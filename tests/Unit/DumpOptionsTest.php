<?php

use App\ValueObjects\DumpOptions;

test('dump options can be created with required parameters', function () {
    $options = new DumpOptions(
        database: 'test_db',
    );

    expect($options->database)->toBe('test_db')
        ->and($options->tables)->toBe([])
        ->and($options->schemaOnly)->toBe(false)
        ->and($options->dataOnly)->toBe(false)
        ->and($options->dropTables)->toBe(false)
        ->and($options->gzip)->toBe(false)
        ->and($options->outputPath)->toBeNull();
});

test('dump options can be created with all parameters', function () {
    $options = new DumpOptions(
        database: 'test_db',
        tables: ['users', 'posts'],
        schemaOnly: true,
        dataOnly: false,
        dropTables: true,
        gzip: true,
        outputPath: '/tmp/dump.sql.gz',
    );

    expect($options->database)->toBe('test_db')
        ->and($options->tables)->toBe(['users', 'posts'])
        ->and($options->schemaOnly)->toBe(true)
        ->and($options->dataOnly)->toBe(false)
        ->and($options->dropTables)->toBe(true)
        ->and($options->gzip)->toBe(true)
        ->and($options->outputPath)->toBe('/tmp/dump.sql.gz');
});

test('dump options validation fails when both schemaOnly and dataOnly are true', function () {
    new DumpOptions(
        database: 'test_db',
        schemaOnly: true,
        dataOnly: true,
    );
})->throws(InvalidArgumentException::class, 'Cannot set both schemaOnly and dataOnly to true');

test('dump options validation fails when database is empty', function () {
    new DumpOptions(
        database: '',
    );
})->throws(InvalidArgumentException::class, 'Database name cannot be empty');

test('dump options defaults are correct', function () {
    $options = new DumpOptions(database: 'test_db');

    expect($options->tables)->toBeArray()
        ->and($options->tables)->toBeEmpty()
        ->and($options->schemaOnly)->toBeFalse()
        ->and($options->dataOnly)->toBeFalse()
        ->and($options->dropTables)->toBeFalse()
        ->and($options->gzip)->toBeFalse()
        ->and($options->outputPath)->toBeNull();
});
