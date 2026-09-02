<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\Enums\SortDirection;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageData;
use DirectoryTree\ImapEngine\MessageQuery;
use Illuminate\Support\ItemNotFoundException;

function query(?Mailbox $mailbox = null): MessageQuery
{
    return new MessageQuery(
        new Folder($mailbox ?? new Mailbox, 'test'),
        new ImapQueryBuilder
    );
}

test('find resolves the uid from fetched attributes regardless of their order', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (FLAGS (\\Seen) UID 42)',
        'TAG2 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $query = new MessageQuery(new Folder($mailbox, 'INBOX'), new ImapQueryBuilder);
    $message = $query->find(1, ImapFetchIdentifier::MessageNumber);

    $stream->assertWritten('TAG2 FETCH 1 (UID)');
    expect($message->uid())->toBe(42);
});

test('find returns null when fetch returns no messages', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        'TAG2 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $query = new MessageQuery(new Folder($mailbox, 'INBOX'), new ImapQueryBuilder);

    expect($query->find(42))->toBeNull();
});

test('find or fail throws when fetch returns no messages', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        'TAG2 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $query = new MessageQuery(new Folder($mailbox, 'INBOX'), new ImapQueryBuilder);

    expect(fn () => $query->findOrFail(42))->toThrow(ItemNotFoundException::class);
});

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

test('message id forwards to query builder', function () {
    $query = query();

    expect($query->messageId('unique-message-id@server.example.com'))->toBe($query);
    expect($query->toImap())->toBe('HEADER MESSAGE-ID "unique-message-id@server.example.com"');
});

test('fetch items can be added and removed', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1',
        'TAG2 OK UID SEARCH completed',
        '* 1 FETCH (UID 1 RFC822.SIZE 1024)',
        'TAG3 OK UID FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)
        ->with(MessageData::flags(), MessageData::size())
        ->without(MessageData::flags())
        ->get();

    $stream->assertWritten('TAG3 UID FETCH 1 (RFC822.SIZE)');
});

test('fetch items can be replaced', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1',
        'TAG2 OK UID SEARCH completed',
        '* 1 FETCH (UID 1 BODY[HEADER] {0}',
        '',
        ' BODY[TEXT] {0}',
        '',
        ')',
        'TAG3 OK UID FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)
        ->with(MessageData::flags())
        ->only(MessageData::headers(), MessageData::text())
        ->get();

    $stream->assertWritten('TAG3 UID FETCH 1 (BODY[HEADER] BODY[TEXT])');
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

    $stream->assertWritten('TAG2 UID STORE 1:3 +FLAGS.SILENT (\Deleted)');
});

test('destroy with expunge only expunges the given messages', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        'TAG2 OK UID STORE completed',
        'TAG3 OK UID EXPUNGE completed',
    ]);

    $mailbox = Mailbox::make();

    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)->destroy([1, 2, 3], expunge: true);

    $stream->assertWritten('TAG2 UID STORE 1:3 +FLAGS.SILENT (\Deleted)');
    $stream->assertWritten('TAG3 UID EXPUNGE 1:3');
});

test('orderByUid returns messages in ascending UID order', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 3 1 2',
        'TAG2 OK UID SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $uids = query($mailbox)->orderByUid()->get()->map(
        fn ($message) => $message->uid()
    )->all();

    expect($uids)->toBe([1, 2, 3]);
});

test('orderByUid returns messages in descending UID order', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 2 3 1',
        'TAG2 OK UID SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $uids = query($mailbox)->orderByUid(SortDirection::Descending)->get()->map(
        fn ($message) => $message->uid()
    )->all();

    expect($uids)->toBe([3, 2, 1]);
});

test('orderByUid returns query instance for chaining', function () {
    $query = query();

    expect($query->orderByUid())->toBe($query);
});

test('orderByUid fails with incorrect string direction', function () {
    query()->orderByUid('invalid');
})->throws(ValueError::class);

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

test('append with single flag converts to array', function (mixed $flag) {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        'TAG2 OK [APPENDUID 1234567890 1] APPEND completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $folder = new Folder($mailbox, 'INBOX');
    $query = new MessageQuery($folder, new ImapQueryBuilder);

    $result = $query->append('Hello world', $flag);

    expect($result->uidValidity())->toBe(1234567890)
        ->and($result->uid())->toBe(1);
    $stream->assertWritten('TAG2 APPEND "INBOX" (\\Seen) "Hello world"');
})->with([ImapFlag::Seen, '\\Seen']);

test('flag adds flag to all matching messages', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1 2 3',
        'TAG2 OK SEARCH completed',
        'TAG3 OK UID STORE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->flag(ImapFlag::Seen, '+');

    expect($count)->toBe(3);
    $stream->assertWritten('TAG3 UID STORE 1:3 +FLAGS.SILENT (\Seen)');
});

test('flag removes flag from all matching messages', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 4 5',
        'TAG2 OK SEARCH completed',
        'TAG3 OK UID STORE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->flag(ImapFlag::Flagged, '-');

    expect($count)->toBe(2);
    $stream->assertWritten('TAG3 UID STORE 4:5 -FLAGS.SILENT (\Flagged)');
});

test('flag returns zero when no messages match', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH',
        'TAG2 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->flag(ImapFlag::Seen, '+');

    expect($count)->toBe(0);
});

test('markRead marks all matching messages as read', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1 2 3',
        'TAG2 OK SEARCH completed',
        'TAG3 OK UID STORE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->markRead();

    expect($count)->toBe(3);
    $stream->assertWritten('TAG3 UID STORE 1:3 +FLAGS.SILENT (\Seen)');
});

test('markUnread marks all matching messages as unread', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1 2',
        'TAG2 OK SEARCH completed',
        'TAG3 OK UID STORE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->markUnread();

    expect($count)->toBe(2);
    $stream->assertWritten('TAG3 UID STORE 1:2 -FLAGS.SILENT (\Seen)');
});

test('markFlagged flags all matching messages', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 5 6 7 8',
        'TAG2 OK SEARCH completed',
        'TAG3 OK UID STORE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->markFlagged();

    expect($count)->toBe(4);
    $stream->assertWritten('TAG3 UID STORE 5:8 +FLAGS.SILENT (\Flagged)');
});

test('uid range to infinity searches with numeric upper bound', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH',
        'TAG2 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $messages = query($mailbox)->uid(42, INF)->get();

    expect($messages)->toBeEmpty();
    $stream->assertWritten('TAG2 UID SEARCH UID 42:4294967295');
});

test('unmarkFlagged unflags all matching messages', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 10',
        'TAG2 OK SEARCH completed',
        'TAG3 OK UID STORE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->unmarkFlagged();

    expect($count)->toBe(1);
    $stream->assertWritten('TAG3 UID STORE 10 -FLAGS.SILENT (\Flagged)');
});

test('delete marks all matching messages as deleted', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1 2 3',
        'TAG2 OK SEARCH completed',
        'TAG3 OK UID STORE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->delete();

    expect($count)->toBe(3);
    $stream->assertWritten('TAG3 UID STORE 1:3 +FLAGS.SILENT (\Deleted)');
});

test('delete with expunge also expunges folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1 2',
        'TAG2 OK SEARCH completed',
        'TAG3 OK UID STORE completed',
        'TAG4 OK UID EXPUNGE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $folder = new Folder($mailbox, 'INBOX');
    $query = new MessageQuery($folder, new ImapQueryBuilder);

    $count = $query->delete(expunge: true);

    expect($count)->toBe(2);
    $stream->assertWritten('TAG3 UID STORE 1:2 +FLAGS.SILENT (\Deleted)');
    $stream->assertWritten('TAG4 UID EXPUNGE 1:2');
});

test('move moves all matching messages to folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1 2 3',
        'TAG2 OK SEARCH completed',
        'TAG3 OK UID MOVE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->move('Archive');

    expect($count)->toBe(3);
    $stream->assertWritten('TAG3 UID MOVE 1:3 "Archive"');
});

test('move returns zero when no messages match', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH',
        'TAG2 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->move('Archive');

    expect($count)->toBe(0);
});

test('copy copies all matching messages to folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 4 5',
        'TAG2 OK SEARCH completed',
        'TAG3 OK UID COPY completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->copy('Backup');

    expect($count)->toBe(2);
    $stream->assertWritten('TAG3 UID COPY 4:5 "Backup"');
});

test('copy returns zero when no messages match', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH',
        'TAG2 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $count = query($mailbox)->copy('Backup');

    expect($count)->toBe(0);
});

test('sortBy fails with incorrect string key', function () {
    query()->sortBy('invalid');
})->throws(ValueError::class);

test('sortBy fails with incorrect string direction', function () {
    query()->sortBy('date', 'invalid');
})->throws(ValueError::class);

test('sortBy sends correct sort command with ascending order', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SORT',
        'TAG2 OK CAPABILITY completed',
        '* SORT 3 1 2',
        'TAG3 OK SORT completed',
        '* 1 FETCH (UID 1 FLAGS ())',
        '* 2 FETCH (UID 2 FLAGS ())',
        '* 3 FETCH (UID 3 FLAGS ())',
        'TAG4 OK UID FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $uids = query($mailbox)
        ->orderByUid(SortDirection::Descending)
        ->sortBy('date')
        ->with(MessageData::flags())
        ->get()
        ->map(fn ($message) => $message->uid())
        ->all();

    $stream->assertWritten('TAG3 UID SORT (DATE) UTF-8 ALL');

    expect($uids)->toBe([3, 1, 2]);
});

test('sortBy recognizes extended SORT capabilities', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SORT=DISPLAY',
        'TAG2 OK CAPABILITY completed',
        '* SORT 1',
        'TAG3 OK SORT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)->sortBy('date')->get();

    $stream->assertWritten('TAG3 UID SORT (DATE) UTF-8 ALL');
});

test('sortBy sends correct sort command with descending order', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SORT',
        'TAG2 OK CAPABILITY completed',
        '* SORT 2 1 3',
        'TAG3 OK SORT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)->sortBy('date', SortDirection::Descending)->get();

    $stream->assertWritten('TAG3 UID SORT (REVERSE DATE) UTF-8 ALL');
});

test('sortBy sends multiple sort criteria in priority order', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SORT',
        'TAG2 OK CAPABILITY completed',
        '* SORT 2 1 3',
        'TAG3 OK SORT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)
        ->sortBy('subject')
        ->sortBy('date', SortDirection::Descending)
        ->get();

    $stream->assertWritten('TAG3 UID SORT (SUBJECT REVERSE DATE) UTF-8 ALL');
});

test('orderByUid replaces server sorting', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 3 1 2',
        'TAG2 OK UID SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $uids = query($mailbox)
        ->sortBy('date')
        ->orderByUid()
        ->get()
        ->map(fn ($message) => $message->uid())
        ->all();

    expect($uids)->toBe([1, 2, 3]);
});

test('sortBy works with ImapSortKey enum', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SORT',
        'TAG2 OK CAPABILITY completed',
        '* SORT 1 2 3',
        'TAG3 OK SORT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)->sortBy(ImapSortKey::Subject)->get();

    $stream->assertWritten('TAG3 UID SORT (SUBJECT) UTF-8 ALL');
});

test('sortBy combined with search criteria', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SORT',
        'TAG2 OK CAPABILITY completed',
        '* SORT 5 3',
        'TAG3 OK SORT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)->unseen()->sortBy('arrival', SortDirection::Descending)->get();

    $stream->assertWritten('TAG3 UID SORT (REVERSE ARRIVAL) UTF-8 UNSEEN');
});

test('sortBy throws exception when SORT capability is not available', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1',
        'TAG2 OK CAPABILITY completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)->sortBy('date', 'desc')->get();
})->throws(ImapCapabilityException::class);
