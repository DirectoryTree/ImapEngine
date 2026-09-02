<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Mailbox;

test('it requests special-use attributes when supported', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SPECIAL-USE',
        'TAG2 OK CAPABILITY completed',
        '* LIST (\\Sent \\HasNoChildren) "/" "Outgoing"',
        'TAG3 OK LIST completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    expect($mailbox->folders()->sent()?->path())->toBe('Outgoing');

    $stream->assertWritten('TAG3 LIST "" "*" RETURN (SPECIAL-USE)');
});

test('it falls back to conventional folder names', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1',
        'TAG2 OK CAPABILITY completed',
        '* LIST (\\HasNoChildren) "/" "Sent Items"',
        'TAG3 OK LIST completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    expect($mailbox->folders()->sent()?->path())->toBe('Sent Items');

    $stream->assertWritten('TAG3 LIST "" "*"');
    $stream->assertNotWritten('TAG3 LIST "" "*" RETURN (SPECIAL-USE)');
});

test('it resolves special-use attributes from an ordinary list response', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1',
        'TAG2 OK CAPABILITY completed',
        '* LIST (\\Sent \\HasNoChildren) "/" "Outgoing"',
        'TAG3 OK LIST completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    expect($mailbox->folders()->sent()?->path())->toBe('Outgoing');

    $stream->assertWritten('TAG3 LIST "" "*"');
});

test('it prefers special-use attributes over folder names', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SPECIAL-USE',
        'TAG2 OK CAPABILITY completed',
        '* LIST (\\HasNoChildren) "/" "Sent"',
        '* LIST (\\Sent \\HasNoChildren) "/" "Outgoing"',
        'TAG3 OK LIST completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    expect($mailbox->folders()->sent()?->path())->toBe('Outgoing');
});

test('it returns null when a special-use folder cannot be resolved', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1',
        'TAG2 OK CAPABILITY completed',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG3 OK LIST completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    expect($mailbox->folders()->archive())->toBeNull();
});
