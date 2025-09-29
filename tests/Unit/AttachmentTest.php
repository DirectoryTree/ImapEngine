<?php

use DirectoryTree\ImapEngine\Attachment;
use GuzzleHttp\Psr7\LazyOpenStream;

test('extension', function () {
    $stream = new LazyOpenStream('test.jpg', 'r');

    $ext = (new Attachment('test.jpg', null, 'image/jpeg', 'attachment', $stream))->extension();

    expect($ext)->toBe('jpg');
});

test('extension with content type', function () {
    $stream = new LazyOpenStream('test', 'r');

    $ext = (new Attachment('test', null, 'image/jpeg', 'attachment', $stream))->extension();

    expect($ext)->toBe('jpg');
});
