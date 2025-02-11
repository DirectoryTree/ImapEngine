<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\CommandFailedException;
use DirectoryTree\ImapEngine\Mailbox;

test('connect', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
    ]);

    $mailbox = Mailbox::make();

    $mailbox->connect(new ImapConnection($stream));

    expect($mailbox->connected())->toBeTrue();
});

test('connect throws exception with bad response', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 BAD Authentication failed',
    ]);

    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));
})->throws(CommandFailedException::class, 'IMAP command "TAG1 LOGIN [redacted] [redacted]" failed. Response: "TAG1 BAD Authentication failed"');
