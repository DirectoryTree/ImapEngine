<?php

use DirectoryTree\ImapEngine\Collections\ResponseCollection;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Literal;
use DirectoryTree\ImapEngine\Connection\Tokens\Number;
use DirectoryTree\ImapEngine\Enums\ImapFetchIdentifier;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageQuery;
use Illuminate\Support\Collection;

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
    expect($query->subject('hello')->toImapTokens())->toBe(['SUBJECT "hello"']);
    expect($query->getWheres()[0]['value'])->toBe('hello');
});

test('search and sort execute query token overrides', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH',
        'TAG2 OK SEARCH completed',
        '* CAPABILITY IMAP4rev1 SORT',
        'TAG3 OK CAPABILITY completed',
        '* SORT',
        'TAG4 OK SORT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $builder = new class extends ImapQueryBuilder
    {
        public function toImapTokens(): array
        {
            return [...parent::toImapTokens(), 'UNSEEN'];
        }
    };

    $query = new MessageQuery(new Folder($mailbox, 'test'), $builder);
    $query->where(fn ($nested) => $nested->seen())->count();
    $query->sortBy('date')->get();

    $stream->assertWritten("TAG2 UID SEARCH SEEN UNSEEN UNSEEN\r\n");
    $stream->assertWritten("TAG4 UID SORT (DATE) UTF-8 SEEN UNSEEN UNSEEN\r\n");
});

test('search and sort preserve literals in query token overrides', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '+ Ready for subject',
        '+ Ready for body',
        '* SEARCH',
        'TAG2 OK SEARCH completed',
        '* CAPABILITY IMAP4rev1 SORT',
        'TAG3 OK CAPABILITY completed',
        '+ Ready for subject',
        '+ Ready for body',
        '* SORT',
        'TAG4 OK SORT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $builder = new class extends ImapQueryBuilder
    {
        public function toImapTokens(): array
        {
            return [...parent::toImapTokens(), 'UNSEEN'];
        }
    };

    $query = new MessageQuery(new Folder($mailbox, 'test'), $builder);
    $query->subject('café')->orWhere('body', "line {3}\r\nend");

    expect($query->toImap())->toBe("OR (SUBJECT {5}\r\ncafé) (BODY {13}\r\nline {3}\r\nend) UNSEEN");
    $query->count();
    $query->sortBy('date')->get();

    $stream->assertWritten("TAG2 UID SEARCH CHARSET UTF-8 OR (SUBJECT {5}\r\n");
    $stream->assertWritten("café) (BODY {13}\r\n");
    $stream->assertWritten("line {3}\r\nend) UNSEEN\r\n");

    $stream->assertWritten("TAG4 UID SORT (DATE) UTF-8 OR (SUBJECT {5}\r\n");
    $stream->assertWritten("café) (BODY {13}\r\n");
    $stream->assertWritten("line {3}\r\nend) UNSEEN\r\n");
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

    $uid = $query->append('Hello world', $flag);

    expect($uid)->toBe(1);
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

test('search preserves literal values inside nested criteria', function (string $subject, string $charset) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '+ Ready for literal data',
        '+ Ready for literal data',
        '* SEARCH 1',
        'TAG2 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $query = query($mailbox)
        ->body("first\nsecond")
        ->orWhere('subject', $subject)
        ->header('Message-ID', '0');

    expect($query->toImap())->toBe("OR (BODY {12}\r\nfirst\nsecond) (SUBJECT {10}\r\n{$subject}) HEADER MESSAGE-ID \"0\"");
    expect($query->count())->toBe(1);

    $stream->assertWritten("TAG2 UID SEARCH {$charset}OR (BODY {12}\r\n");
    $stream->assertWritten("first\nsecond) (SUBJECT {10}\r\n");
    $stream->assertWritten("{$subject}) HEADER MESSAGE-ID \"0\"\r\n");
})->with([
    'ASCII' => ["third\rline", ''],
    'UTF-8' => ["café\rline", 'CHARSET UTF-8 '],
]);

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
        'TAG4 OK EXPUNGE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $folder = new Folder($mailbox, 'INBOX');
    $query = new MessageQuery($folder, new ImapQueryBuilder);

    $count = $query->delete(expunge: true);

    expect($count)->toBe(2);
    $stream->assertWritten('TAG3 UID STORE 1:2 +FLAGS.SILENT (\Deleted)');
    $stream->assertWritten('TAG4 EXPUNGE');
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
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    query($mailbox)->sortBy('date')->get();

    $stream->assertWritten('TAG3 UID SORT (DATE) UTF-8 ALL');
});

test('sort sends literal search values using its existing charset', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SORT',
        'TAG2 OK CAPABILITY completed',
        '+ Ready for literal data',
        '* SORT',
        'TAG3 OK SORT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    expect(query($mailbox)->text('café')->sortBy('date')->get())->toBeEmpty();

    $stream->assertWritten("TAG3 UID SORT (DATE) UTF-8 TEXT {5}\r\n");
    $stream->assertWritten("café\r\n");
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

    query($mailbox)->sortBy('date', 'desc')->get();

    $stream->assertWritten('TAG3 UID SORT (REVERSE DATE) UTF-8 ALL');
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

    query($mailbox)->unseen()->sortBy('arrival', 'desc')->get();

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

test('chunk orders all identifiers before paging and restores pagination', function (string $order, int $startChunk, array $expected) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 50 2 100 1 10',
        'TAG2 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);
    $query->setFetchOrder($order)->limit(7, 3);
    $chunks = [];

    $query->chunk(function ($messages, $page) use (&$chunks) {
        $chunks[$page] = $messages->map->uid()->all();

        expect($messages->total())->toBe(5);
    }, 2, $startChunk);

    expect($chunks)->toBe($expected);
    expect($query->getLimit())->toBe(7);
    expect($query->getPage())->toBe(3);
})->with([
    ['asc', 1, [1 => [1, 2], 2 => [10, 50], 3 => [100]]],
    ['desc', 1, [1 => [100, 50], 2 => [10, 2], 3 => [1]]],
    ['asc', 2, [2 => [10, 50], 3 => [100]]],
    ['desc', 2, [2 => [10, 2], 3 => [1]]],
]);

test('a failed chunk callback preserves the query page and limit', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 1 2 3',
        'TAG2 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);
    $query->limit(7, 3);

    expect(fn () => $query->chunk(fn () => throw new LogicException('Chunk failed'), 2))
        ->toThrow(LogicException::class, 'Chunk failed');

    expect($query->getLimit())->toBe(7)
        ->and($query->getPage())->toBe(3);
});

test('chunk sorts the search results only once', function (string $order) {
    $identifiers = new class(['50', '2', '100', '1', '10']) extends Collection
    {
        public int $sorts = 0;

        public function sort($callback = null)
        {
            $this->sorts++;

            return parent::sort($callback);
        }

        public function sortDesc($options = SORT_REGULAR)
        {
            $this->sorts++;

            return parent::sortDesc($options);
        }
    };

    $query = new class(new Folder(new Mailbox, 'test'), new ImapQueryBuilder) extends MessageQuery
    {
        public Collection $identifiers;

        protected function search(): Collection
        {
            return $this->identifiers;
        }
    };

    $query->identifiers = $identifiers;
    $query->setFetchOrder($order);
    $processed = 0;

    $query->chunk(function ($messages) use (&$processed) {
        $processed += $messages->count();
    }, 2);

    expect($processed)->toBe(5);
    expect($identifiers->sorts)->toBe(1);
})->with(['asc', 'desc']);

test('get still sorts identifiers before pagination', function (string $order, array $expected) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 50 2 100 1 10',
        'TAG2 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);

    $messages = $query->setFetchOrder($order)->limit(2, 2)->get();

    expect($messages->map->uid()->all())->toBe($expected);
    expect($messages->total())->toBe(5);
})->with([
    ['asc', [10, 50]],
    ['desc', [10, 2]],
]);

test('get preserves server sort order before pagination', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SORT',
        'TAG2 OK CAPABILITY completed',
        '* SORT 50 2 100 1 10',
        'TAG3 OK SORT completed',
        '* 1 FETCH (UID 1 FLAGS ())',
        '* 2 FETCH (UID 100 FLAGS ())',
        'TAG4 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);

    $messages = $query->sortBy('date')->withFlags()->limit(2, 2)->get();

    expect($messages->map->uid()->all())->toBe([100, 1]);
    expect($messages->total())->toBe(5);
});

test('chunk fetches metadata for each sorted page', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 50 2 100',
        'TAG2 OK SEARCH completed',
        '* 1 FETCH (UID 2 RFC822.SIZE 20)',
        '* 2 FETCH (UID 50 RFC822.SIZE 500)',
        'TAG3 OK FETCH completed',
        '* 3 FETCH (UID 100 RFC822.SIZE 1000)',
        'TAG4 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);
    $chunks = [];

    $query->oldest()->withSize()->chunk(function ($messages) use (&$chunks) {
        $chunks[] = $messages->mapWithKeys(fn ($message) => [$message->uid() => $message->size()])->all();
    }, 2);

    expect($chunks)->toBe([[2 => 20, 50 => 500], [100 => 1000]]);
    $stream->assertWritten('TAG3 UID FETCH 2,50 (RFC822.SIZE)');
    $stream->assertWritten('TAG4 UID FETCH 100 (RFC822.SIZE)');
});

test('chunk skips unavailable pages without changing pagination', function (string $search, int $startChunk) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        $search,
        'TAG2 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);
    $query->limit(7, 3);
    $calls = 0;

    $query->chunk(function () use (&$calls) {
        $calls++;
    }, 2, $startChunk);

    expect($calls)->toBe(0);
    expect($query->getLimit())->toBe(7);
    expect($query->getPage())->toBe(3);
})->with([
    ['* SEARCH', 1],
    ['* SEARCH 1 2 3', 3],
]);

test('query can be reused with a different order after stopping chunking', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 50 2 100 1 10',
        'TAG2 OK SEARCH completed',
        '* SEARCH 50 2 100 1 10',
        'TAG3 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);
    $query->oldest()->limit(2, 2);
    $chunks = [];

    $query->chunk(function ($messages, $page) use (&$chunks) {
        $chunks[$page] = $messages->map->uid()->all();

        return false;
    }, 3);

    expect($chunks)->toBe([1 => [1, 2, 10]]);
    expect($query->getLimit())->toBe(2);
    expect($query->getPage())->toBe(2);
    expect($query->newest()->get()->map->uid()->all())->toBe([10, 2]);
});

test('fetch reads fields in any response order', function (array $order) {
    $head = "Subject: Example\r\nContent-Type: text/plain\r\n\r\n";
    $body = str_repeat("UID FLAGS BODYSTRUCTURE [HEADER] [TEXT]\r\n", 1024);
    $fields = [
        'uid' => 'UID 42',
        'flags' => 'FLAGS (\\Seen custom)',
        'size' => 'RFC822.SIZE 123456',
        'head' => 'BODY[HEADER] {'.strlen($head)."}\r\n".$head,
        'body' => 'BODY[TEXT] {'.strlen($body)."}\r\n".$body,
        'structure' => 'BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5)',
    ];

    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 42',
        'TAG2 OK SEARCH completed',
    ]);
    $stream->feedRaw('* 1 FETCH ('.implode(' ', array_map(fn ($key) => $fields[$key], $order)).")\r\n");
    $stream->feed('TAG3 OK FETCH completed');

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);

    $message = $query->withFlags()->withSize()->withHeaders()->withBody()->withBodyStructure()->get()->sole();

    expect($message->uid())->toBe(42);
    expect($message->flags())->toBe(['\\Seen', 'custom']);
    expect($message->size())->toBe(123456);
    expect($message->head())->toBe($head);
    expect($message->body())->toBe($body);
    expect($message->bodyStructure())->toHaveCount(1);
})->with([
    [['uid', 'flags', 'size', 'head', 'body', 'structure']],
    [['structure', 'body', 'head', 'size', 'flags', 'uid']],
    [['body', 'flags', 'structure', 'uid', 'head', 'size']],
]);

test('fetch preserves defaults for missing fields and zero size', function (string $fields) {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 42',
        'TAG2 OK SEARCH completed',
        '* 1 FETCH ('.$fields.')',
        'TAG3 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);

    $message = $query->withSize()->get()->sole();

    expect($message->uid())->toBe(42);
    expect($message->flags())->toBe([]);
    expect($message->size())->toBeNull();
    expect($message->head())->toBe('');
    expect($message->body())->toBe('');
    expect($message->bodyStructure())->toBeNull();
})->with([
    'UID 42',
    'UID 42 FLAGS () RFC822.SIZE 0 BODY[HEADER] "" BODY[TEXT] ""',
]);

test('fetch keeps the first occurrence of duplicate fields', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 42',
        'TAG2 OK SEARCH completed',
        '* 1 FETCH (UID 42 RFC822.SIZE 100 FLAGS (\\Seen) BODY[HEADER] "first header" BODY[TEXT] "first body" UID 99 RFC822.SIZE 200 FLAGS (\\Flagged) BODY[HEADER] "second header" BODY[TEXT] "second body")',
        'TAG3 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);

    $message = $query->withFlags()->withSize()->withHeaders()->withBody()->get()->sole();

    expect($message->uid())->toBe(42);
    expect($message->size())->toBe(100);
    expect($message->flags())->toBe(['\\Seen']);
    expect($message->head())->toBe('first header');
    expect($message->body())->toBe('first body');
});

test('fetch does not confuse nested or quoted values with field names', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 42',
        'TAG2 OK SEARCH completed',
        '* 1 FETCH (X-EXTRA (UID 99 RFC822.SIZE 999) X-LABEL "UID" X-HEADER "[HEADER]" BODY[1] "other body part" UID 42 RFC822.SIZE 100 BODY[HEADER] "header" BODY[TEXT] "body")',
        'TAG3 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);

    $message = $query->withSize()->withHeaders()->withBody()->get()->sole();

    expect($message->uid())->toBe(42);
    expect($message->size())->toBe(100);
    expect($message->head())->toBe('header');
    expect($message->body())->toBe('body');
});

test('fetch extracts fields without stringifying literals or nested lists', function () {
    $literal = new class('message body') extends Literal
    {
        public function __toString(): string
        {
            throw new RuntimeException('Literal contents should not be stringified during field extraction.');
        }
    };

    $nested = new class([new Atom('UID'), new Number('99')]) extends ListData
    {
        public function __toString(): string
        {
            throw new RuntimeException('Nested lists should not be stringified during field extraction.');
        }
    };

    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* SEARCH 42',
        'TAG2 OK SEARCH completed',
    ]);

    $connection = new class($stream) extends ImapConnection
    {
        public ResponseCollection $responses;

        public function fetch(array|string $items, array|int $from, mixed $to = null, ImapFetchIdentifier $identifier = ImapFetchIdentifier::Uid): ResponseCollection
        {
            return $this->responses;
        }
    };
    $connection->responses = new ResponseCollection([
        new UntaggedResponse([
            new Atom('*'), new Number('1'), new Atom('FETCH'),
            new ListData([
                new Atom('X-EXTENSION'), $nested,
                new Atom('BODY'), new ResponseCodeData([new Atom('TEXT')]), $literal,
                new Atom('UID'), new Number('42'),
            ]),
        ]),
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect($connection);
    $query = new MessageQuery(new Folder($mailbox, 'test'), new ImapQueryBuilder);

    $message = $query->withBody()->get()->sole();

    expect($message->uid())->toBe(42);
    expect($message->body())->toBe('message body');
});
