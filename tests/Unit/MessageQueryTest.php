<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageQuery;

function query(?Mailbox $mailbox = null): MessageQuery
{
    return new MessageQuery(
        new Folder($mailbox ?? new Mailbox, 'test'),
        new ImapQueryBuilder
    );
}

test('passthru', function () {
    $query = query();

    expect($query->toImap())->toBe('');
    expect($query->isEmpty())->toBeTrue();
});

test('where', function () {
    $query = query()
        ->where('subject', 'hello')
        ->toImap();

    expect($query)->toBe('SUBJECT "hello"');
});

test('destroy', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        'TAG2 OK UID STORE completed',
    ]);

    $mailbox = Mailbox::make();

    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)->destroy(1);

    $stream->assertWritten('TAG2 UID STORE 1 +FLAGS.SILENT (\Deleted)');
});

test('destroy with multiple messages', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        'TAG2 OK UID STORE completed',
    ]);

    $mailbox = Mailbox::make();

    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)->destroy([1, 2, 3]);

    $stream->assertWritten('TAG2 UID STORE 1,2,3 +FLAGS.SILENT (\Deleted)');
});
