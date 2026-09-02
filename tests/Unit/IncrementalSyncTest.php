<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Fetch\ChangedSince;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Selection\CondStore;
use DirectoryTree\ImapEngine\Selection\QuickResync;
use DirectoryTree\ImapEngine\Store\UnchangedSince;

test('select returns typed condstore metadata', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        '* 3 EXISTS',
        '* 0 RECENT',
        '* OK [UIDVALIDITY 777] UIDs valid',
        '* OK [UIDNEXT 10] Predicted next UID',
        '* OK [HIGHESTMODSEQ 42] Highest',
        '* OK [PERMANENTFLAGS (\\Seen \\*)] Flags permitted',
        'TAG1 OK SELECT completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->select('INBOX', new CondStore);

    $stream->assertWritten('TAG1 SELECT "INBOX" (CONDSTORE)');
    expect($result->exists())->toBe(3);
    expect($result->recent())->toBe(0);
    expect($result->uidValidity())->toBe(777);
    expect($result->uidNext())->toBe(10);
    expect($result->highestModSequence())->toBe(42);
    expect($result->permanentFlags())->toBe(['\\Seen', '\\*']);
    expect($result->supportsModSequences())->toBeTrue();
});

test('select reports when mod sequences are unavailable', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        '* OK [NOMODSEQ] No persistent mod sequences',
        'TAG1 OK SELECT completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->select('INBOX', new CondStore);

    expect($result->highestModSequence())->toBeNull();
    expect($result->supportsModSequences())->toBeFalse();
});

test('quick resync selection includes the saved checkpoint', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 3 FLAGS (\\Seen) MODSEQ (43))',
        '* VANISHED (EARLIER) 2',
        'TAG1 OK SELECT completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $result = $connection->select(
        'INBOX',
        new QuickResync(777, 42, [1, 2, 3, 7]),
    );

    $stream->assertWritten('TAG1 SELECT "INBOX" (QRESYNC (777 42 1:3,7))');
    expect($result->changes()->messages()[0]->uid())->toBe(3);
    expect($result->changes()->vanishedUids())->toBe([2]);
});

test('enable sends one or more capabilities', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        '* ENABLED QRESYNC',
        'TAG1 OK ENABLE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->enable('QRESYNC');

    $stream->assertWritten('TAG1 ENABLE QRESYNC');
    expect($responses->count())->toBe(1);
});

test('fetch changes returns changed and vanished messages', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        '* 2 FETCH (UID 7 FLAGS (\\Seen) MODSEQ (43))',
        '* VANISHED (EARLIER) 3:4,6',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $changes = $connection->fetch([1, 2, 3, 4, 6, 7], 'FLAGS', modifiers: new ChangedSince(42, vanished: true));

    $stream->assertWritten('TAG1 UID FETCH 1:4,6:7 (FLAGS) (CHANGEDSINCE 42 VANISHED)');
    expect($changes->messages())->toHaveCount(1);
    expect($changes->messages()[0]->uid())->toBe(7);
    expect($changes->messages()[0]->flags())->toBe(['\\Seen']);
    expect($changes->messages()[0]->modSequence())->toBe(43);
    expect($changes->vanished())->toHaveCount(1);
    expect($changes->vanished()[0]->uids())->toBe([3, 4, 6]);
    expect($changes->vanished()[0]->earlier())->toBeTrue();
    expect($changes->vanishedUids())->toBe([3, 4, 6]);
});

test('conditional store returns updated messages', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        '* 2 FETCH (UID 7 FLAGS (\\Seen \\Flagged) MODSEQ (44))',
        'TAG1 OK STORE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->store(7, '\\Flagged', modifiers: new UnchangedSince(43));

    $stream->assertWritten('TAG1 UID STORE 7 (UNCHANGEDSINCE 43) +FLAGS.SILENT (\\Flagged)');
    expect($result->successful())->toBeTrue();
    expect($result->modified())->toBe([]);
    expect($result->messages())->toHaveCount(1);
    expect($result->messages()[0]->modSequence())->toBe(44);
});

test('conditional store returns conflicting message uids', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 NO [MODIFIED 8:9] Conditional STORE failed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->store([7, 8, 9], '\\Seen', modifiers: new UnchangedSince(43));

    expect($result->successful())->toBeFalse();
    expect($result->modified())->toBe([8, 9]);
});

test('mailbox enables qresync before selecting and keeps the folder selected', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 CONDSTORE QRESYNC',
        'TAG2 OK CAPABILITY completed',
        '* ENABLED QRESYNC',
        'TAG3 OK ENABLE completed',
        '* 3 EXISTS',
        '* OK [UIDVALIDITY 777] UIDs valid',
        '* OK [HIGHESTMODSEQ 42] Highest',
        'TAG4 OK SELECT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $folder = new Folder($mailbox, 'INBOX');

    $selection = $folder->select(options: new QuickResync(777, 40, [1, 2, 3]));
    $folder->messages();

    $stream->assertWritten('TAG3 ENABLE QRESYNC');
    $stream->assertWritten('TAG4 SELECT "INBOX" (QRESYNC (777 40 1:3))');
    $stream->assertNotWritten('TAG5 SELECT');
    expect($selection->highestModSequence())->toBe(42);
});

test('message query fetches changes without searching first', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 3 EXISTS',
        'TAG2 OK SELECT completed',
        '* CAPABILITY IMAP4rev1 CONDSTORE',
        'TAG3 OK CAPABILITY completed',
        '* 2 FETCH (UID 7 FLAGS (\\Seen) MODSEQ (43))',
        'TAG4 OK FETCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $folder = new Folder($mailbox, 'INBOX');

    $changes = $folder->messages()->changesSince(42, [1, 2, 7]);

    $stream->assertWritten('TAG4 UID FETCH 1:2,7 (FLAGS) (CHANGEDSINCE 42)');
    $stream->assertNotWritten('UID SEARCH');
    expect($changes->messages()[0]->uid())->toBe(7);
});
