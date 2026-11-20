<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\Exceptions\InvalidCursorException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQuery;
use DirectoryTree\ImapEngine\Pagination\Cursor;

it('resumes from selected UIDs despite deleted messages and later arrivals', function (string $order, int $deleted) {
    $ascending = $order === 'oldest';
    $selected = $ascending ? [1, 2] : [25, 14];
    $remaining = $ascending ? [14, 25] : [2, 1];
    $fetched = array_slice($selected, 0, 2 - $deleted);

    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* STATUS "INBOX" (UIDNEXT 26 UIDVALIDITY 42)',
        'TAG2 OK STATUS completed',
        '* SEARCH 14 2 25 1',
        'TAG3 OK SEARCH completed',
        ...array_map(fn (int $uid) => "* 1 FETCH (UID {$uid} FLAGS (\\Seen))", array_reverse($fetched)),
        'TAG4 OK FETCH completed',
        '* STATUS "INBOX" (UIDNEXT 100 UIDVALIDITY 42)',
        'TAG5 OK STATUS completed',
        '* SEARCH '.implode(' ', array_reverse($remaining)),
        'TAG6 OK SEARCH completed',
        ...array_map(fn (int $uid) => "* 1 FETCH (UID {$uid} FLAGS (\\Seen))", array_reverse($remaining)),
        'TAG7 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'INBOX'), new ImapQueryBuilder);
    $first = $query->withFlags()->{$order}()->cursorPaginate(2);

    expect($first->items()->map(fn (MessageInterface $message) => $message->uid())->all())->toBe($fetched)
        ->and($first->hasMorePages())->toBeTrue()
        ->and($first->nextCursor()->uid)->toBe($selected[1])
        ->and($first->nextCursor()->maxUid)->toBe(25);

    $saved = $first->nextCursor()->encode();
    $restored = new MessageQuery(new Folder($mailbox, 'INBOX'), new ImapQueryBuilder);
    $second = $restored->withFlags()->{$order}()->cursorPaginate(2, $saved);

    expect($second->items()->map(fn (MessageInterface $message) => $message->uid())->all())->toBe($remaining)
        ->and($second->hasMorePages())->toBeFalse()
        ->and($second->nextCursor())->toBeNull();

    $stream->assertWritten('TAG3 UID SEARCH UID 1:25');
    $stream->assertWritten('TAG6 UID SEARCH UID '.($ascending ? '3:25' : '1:13'));
    $stream->assertWritten('TAG4 UID FETCH '.($ascending ? '1:2' : '25,14').' (FLAGS)');
    $stream->assertNotWritten('TAG8');
})->with(['oldest', 'newest'])->with([0, 1, 2]);

it('respects filters and content options without modifying the original query', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* STATUS "INBOX" (UIDNEXT 4 UIDVALIDITY 42)',
        'TAG2 OK STATUS completed',
        '* SEARCH 1 2 3',
        'TAG3 OK SEARCH completed',
        '* 3 FETCH (UID 3 FLAGS (\Seen) BODY[HEADER] {0}',
        '',
        ' BODY[TEXT] {4}',
        'Body',
        ')',
        'TAG4 OK FETCH completed',
        '* STATUS "INBOX" (UIDNEXT 4 UIDVALIDITY 42)',
        'TAG5 OK STATUS completed',
        '* SEARCH 1 2',
        'TAG6 OK SEARCH completed',
        '* 2 FETCH (UID 2 FLAGS () BODY[HEADER] {0}',
        '',
        ' BODY[TEXT] {4}',
        'Next',
        ')',
        'TAG7 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = (new MessageQuery(new Folder($mailbox, 'INBOX'), new ImapQueryBuilder))
        ->since('2026-11-01')->withHeaders()->withBody()->withFlags()->limit(7, 3);
    $original = $query->toImap();

    $first = $query->cursorPaginate(1);
    $second = $query->cursorPaginate(1, $first->nextCursor());

    expect($first->items()->first()->uid())->toBe(3)
        ->and($second->items()->first()->uid())->toBe(2)
        ->and($query->toImap())->toBe($original)
        ->and($query->getLimit())->toBe(7)
        ->and($query->getPage())->toBe(3);

    $stream->assertWritten('TAG3 UID SEARCH SINCE 01-Nov-2026 UID 1:3');
    $stream->assertWritten('TAG6 UID SEARCH SINCE 01-Nov-2026 UID 1:2');
    $stream->assertWritten('TAG4 UID FETCH 3 (FLAGS BODY.PEEK[HEADER] BODY.PEEK[TEXT])');
});

it('paginates identifiers without issuing a content fetch', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* STATUS "INBOX" (UIDNEXT 11 UIDVALIDITY 42)',
        'TAG2 OK STATUS completed',
        '* SEARCH 1 2 10',
        'TAG3 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'INBOX'), new ImapQueryBuilder);
    $page = $query->cursorPaginate(2);

    expect($page->items()->map(fn (MessageInterface $message) => $message->uid())->all())->toBe([10, 2])
        ->and($page->hasMorePages())->toBeTrue();
    $stream->assertNotWritten('UID FETCH');
});

it('rejects a cursor when the folder identity or direction changes', function (Cursor $cursor) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* STATUS "INBOX" (UIDNEXT 11 UIDVALIDITY 42)',
        'TAG2 OK STATUS completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'INBOX'), new ImapQueryBuilder);

    expect(fn () => $query->cursorPaginate(2, $cursor->encode()))->toThrow(InvalidCursorException::class);
    $stream->assertNotWritten('UID SEARCH');
})->with([
    'folder changed' => [new Cursor(mailbox: Mailbox::make()->fingerprint(), folder: 'Junk', uidValidity: 42, uid: 5, maxUid: 10, direction: 'desc')],
    'UID validity changed' => [new Cursor(mailbox: Mailbox::make()->fingerprint(), folder: 'INBOX', uidValidity: 41, uid: 5, maxUid: 10, direction: 'desc')],
    'direction changed' => [new Cursor(mailbox: Mailbox::make()->fingerprint(), folder: 'INBOX', uidValidity: 42, uid: 5, maxUid: 10, direction: 'asc')],
]);

it('rejects a saved cursor from a different mailbox with matching folder and UID validity', function (array $changed) {
    $config = ['host' => 'imap.example.test', 'port' => 993, 'username' => 'alice'];

    $firstStream = new FakeStream;
    $firstStream->open();
    $firstStream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* STATUS "INBOX" (UIDNEXT 11 UIDVALIDITY 42)',
        'TAG2 OK STATUS completed',
        '* SEARCH 1 5 10',
        'TAG3 OK SEARCH completed',
    ]);

    $firstMailbox = Mailbox::make($config);
    $firstMailbox->connect(new ImapConnection($firstStream));
    $query = new MessageQuery(new Folder($firstMailbox, 'INBOX'), new ImapQueryBuilder);
    $saved = $query->cursorPaginate(2)->nextCursor()->encode();

    $secondStream = new FakeStream;
    $secondStream->open();
    $secondStream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* STATUS "INBOX" (UIDNEXT 11 UIDVALIDITY 42)',
        'TAG2 OK STATUS completed',
    ]);

    $secondMailbox = Mailbox::make(array_replace($config, $changed));
    $secondMailbox->connect(new ImapConnection($secondStream));
    $query = new MessageQuery(new Folder($secondMailbox, 'INBOX'), new ImapQueryBuilder);

    expect(fn () => $query->cursorPaginate(2, $saved))->toThrow(InvalidCursorException::class);
    $secondStream->assertNotWritten('UID SEARCH');
})->with([
    'another user' => [['username' => 'bob']],
    'another host' => [['host' => 'other.example.test']],
    'another port' => [['port' => 143]],
]);

it('returns a completed page when no messages match', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* STATUS "INBOX" (UIDNEXT 100 UIDVALIDITY 42)',
        'TAG2 OK STATUS completed',
        '* SEARCH',
        'TAG3 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'INBOX'), new ImapQueryBuilder);
    $page = $query->withBody()->cursorPaginate();

    expect($page->items())->toBeEmpty()
        ->and($page->hasMorePages())->toBeFalse();
    $stream->assertNotWritten('UID FETCH');
});

it('does not search an empty UID range', function (string $order) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* STATUS "INBOX" (UIDNEXT 1 UIDVALIDITY 42)',
        'TAG2 OK STATUS completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'INBOX'), new ImapQueryBuilder);
    $page = $query->{$order}()->cursorPaginate();

    expect($page->items())->toBeEmpty()
        ->and($page->nextCursor())->toBeNull();
    $stream->assertNotWritten('UID SEARCH');
})->with(['oldest', 'newest']);

it('rejects non-UID sorting and invalid page sizes before connecting', function (int $perPage, bool $sort) {
    $query = new MessageQuery(new Folder(new Mailbox, 'INBOX'), new ImapQueryBuilder);
    if ($sort) {
        $query->sortBy(ImapSortKey::Date);
    }

    expect(fn () => $query->cursorPaginate($perPage))->toThrow(InvalidArgumentException::class);
})->with([[0, false], [-1, false], [15, true]]);

it('rejects an invalid encoded cursor rather than restarting the scan', function () {
    $query = new MessageQuery(new Folder(new Mailbox, 'INBOX'), new ImapQueryBuilder);

    expect(fn () => $query->cursorPaginate(15, 'invalid'))->toThrow(JsonException::class);
});
