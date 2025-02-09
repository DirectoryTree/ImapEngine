<?php

use DirectoryTree\ImapEngine\Attachment;
use GuzzleHttp\Psr7\LazyOpenStream;

test('extension', function () {
    $stream = new LazyOpenStream('test.jpg', 'r');

    $ext = (new Attachment('test.jpg', 'image/jpeg', $stream))->extension();

    expect($ext)->toBe('jpg');
});

test('extension with content type', function () {
    $stream = new LazyOpenStream('test', 'r');

    $ext = (new Attachment('test', 'image/jpeg', $stream))->extension();

    expect($ext)->toBe('jpg');
});
