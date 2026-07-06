<?php

use DirectoryTree\ImapEngine\Attachment;
use GuzzleHttp\Psr7\Utils;

test('extension', function () {
    $stream = Utils::streamFor('');

    $ext = (new Attachment('test.jpg', null, 'image/jpeg', 'attachment', $stream))->extension();

    expect($ext)->toBe('jpg');
});

test('extension with content type', function () {
    $stream = Utils::streamFor('');

    $ext = (new Attachment('test', null, 'image/jpeg', 'attachment', $stream))->extension();

    expect($ext)->toBe('jpg');
});

test('contents can be read multiple times', function () {
    $stream = Utils::streamFor('Hello World!');

    $attachment = new Attachment('test.txt', null, 'text/plain', 'attachment', $stream);

    expect($attachment->contents())->toBe('Hello World!');
    expect($attachment->contents())->toBe('Hello World!');
});

test('save writes contents after reading contents', function () {
    $stream = Utils::streamFor('Hello World!');

    $attachment = new Attachment('test.txt', null, 'text/plain', 'attachment', $stream);

    $path = tempnam(sys_get_temp_dir(), 'imap-engine-attachment-');

    $attachment->contents();
    $attachment->save($path);

    expect(file_get_contents($path))->toBe('Hello World!');

    unlink($path);
});
