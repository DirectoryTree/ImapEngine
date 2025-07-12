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

test('oldest sets fetch order to asc', function () {
    $query = query();

    $query->oldest();

    expect($query->getFetchOrder())->toBe('asc');
});

test('newest sets fetch order to desc', function () {
    $query = query();

    $query->newest();

    expect($query->getFetchOrder())->toBe('desc');
});

test('oldest and newest return query instance for chaining', function () {
    $query = query();

    expect($query->oldest())->toBe($query);
    expect($query->newest())->toBe($query);
});

test('each breaks when callback returns false', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1 2 3 4 5',
        'TAG2 OK SEARCH completed',
        '* 5 FETCH (UID 5 FLAGS () BODY[HEADER] {0}',
        '',
        ' BODY[TEXT] {0}',
        '',
        ')',
        '* 4 FETCH (UID 4 FLAGS () BODY[HEADER] {0}',
        '',
        ' BODY[TEXT] {0}',
        '',
        ')',
        'TAG3 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $processedUids = [];

    query($mailbox)->each(function ($message) use (&$processedUids) {
        $processedUids[] = $message->uid();

        // Break after processing the first message (which will be UID 5 due to desc order)
        if ($message->uid() === 5) {
            return false;
        }
    }, 2); // Use chunk size of 2

    // Should only process the first message (UID 5 due to desc order)
    expect($processedUids)->toBe([5]);
});

test('chunk breaks when callback returns false', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1 2 3 4 5',
        'TAG2 OK SEARCH completed',
        '* 5 FETCH (UID 5 FLAGS () BODY[HEADER] {0}',
        '',
        ' BODY[TEXT] {0}',
        '',
        ')',
        '* 4 FETCH (UID 4 FLAGS () BODY[HEADER] {0}',
        '',
        ' BODY[TEXT] {0}',
        '',
        ')',
        'TAG3 OK FETCH completed',
        '* 3 FETCH (UID 3 FLAGS () BODY[HEADER] {0}',
        '',
        ' BODY[TEXT] {0}',
        '',
        ')',
        '* 2 FETCH (UID 2 FLAGS () BODY[HEADER] {0}',
        '',
        ' BODY[TEXT] {0}',
        '',
        ')',
        'TAG4 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $processedChunks = [];

    query($mailbox)->chunk(function ($messages, $page) use (&$processedChunks) {
        $processedChunks[] = $page;

        // Break after processing the first chunk
        if ($page === 1) {
            return false;
        }
    }, 2); // Use chunk size of 2

    // Should only process the first chunk (page 1)
    expect($processedChunks)->toBe([1]);
});
