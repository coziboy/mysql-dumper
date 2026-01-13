<?php

use App\ValueObjects\DumpResult;

test('dump result can be created successfully', function () {
    $result = new DumpResult(
        success: true,
        filePath: '/tmp/dump.sql',
        fileSize: 1024,
        duration: 2.5,
        error: null,
    );

    expect($result->success)->toBe(true)
        ->and($result->filePath)->toBe('/tmp/dump.sql')
        ->and($result->fileSize)->toBe(1024)
        ->and($result->duration)->toBe(2.5)
        ->and($result->error)->toBeNull();
});

test('dump result success factory method creates successful result', function () {
    $result = DumpResult::success(
        filePath: '/tmp/dump.sql',
        fileSize: 2048,
        duration: 1.5,
    );

    expect($result->success)->toBe(true)
        ->and($result->filePath)->toBe('/tmp/dump.sql')
        ->and($result->fileSize)->toBe(2048)
        ->and($result->duration)->toBe(1.5)
        ->and($result->error)->toBeNull();
});

test('dump result failure factory method creates failed result', function () {
    $result = DumpResult::failure('Connection failed');

    expect($result->success)->toBe(false)
        ->and($result->filePath)->toBe('')
        ->and($result->fileSize)->toBe(0)
        ->and($result->duration)->toBe(0.0)
        ->and($result->error)->toBe('Connection failed');
});

test('format file size returns bytes for small files', function () {
    $result = DumpResult::success('/tmp/dump.sql', 512, 1.0);

    expect($result->formatFileSize())->toBe('512 B');
});

test('format file size returns kilobytes', function () {
    $result = DumpResult::success('/tmp/dump.sql', 2048, 1.0);

    expect($result->formatFileSize())->toBe('2 KB');
});

test('format file size returns megabytes', function () {
    $result = DumpResult::success('/tmp/dump.sql', 5242880, 1.0); // 5 MB

    expect($result->formatFileSize())->toBe('5 MB');
});

test('format file size returns gigabytes', function () {
    $result = DumpResult::success('/tmp/dump.sql', 2147483648, 1.0); // 2 GB

    expect($result->formatFileSize())->toBe('2 GB');
});

test('format file size rounds to two decimal places', function () {
    $result = DumpResult::success('/tmp/dump.sql', 1536, 1.0); // 1.5 KB

    expect($result->formatFileSize())->toBe('1.5 KB');
});

test('format file size handles zero bytes', function () {
    $result = DumpResult::success('/tmp/dump.sql', 0, 1.0);

    expect($result->formatFileSize())->toBe('0 B');
});
