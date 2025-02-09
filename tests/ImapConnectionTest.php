<?php

use DirectoryTree\ImapEngine\Connection\FakeStream;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;

test('connect success', function () {
    $stream = new FakeStream;

    $stream->feed('* OK Welcome to IMAP');

    $connection = new ImapConnection($stream);

    expect($connection->connected())->toBeFalse();

    $connection->connect('imap.example.com', 143);

    expect($connection->connected())->toBeTrue();
});

test('connect failure', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('* BAD');

    $connection = new ImapConnection($stream);

    $connection->connect('imap.example.com', 143);
})->throws(ConnectionFailedException::class);

test('imap cert validation', function () {
    $protocol = new ImapConnection;

    expect($protocol->getCertValidation())->toBe(true);

    $protocol->setCertValidation(false);

    expect($protocol->getCertValidation())->toBe(false);
});

test('imap encryption', function () {
    $protocol = new ImapConnection;

    expect($protocol->getEncryption())->toBeNull();

    $protocol->setEncryption('ssl');

    expect($protocol->getEncryption())->toBe('ssl');
});

test('done', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->done();

    $stream->assertWritten('DONE');
});
