<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Enums\ImapIdentifier;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\ImapSort;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Selection\QuickResync;
use DirectoryTree\ImapEngine\SortCriterion;

test('id preserves field names and nil values', function (?array $parameters, string $expected) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* ID NIL',
        'TAG1 OK ID completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $connection->id($parameters);

    $stream->assertWritten("TAG1 ID $expected");
})->with([
    [null, 'NIL'],
    [[], '()'],
    [['name' => 'ImapEngine', 'version' => null], '("name" "ImapEngine" "version" NIL)'],
]);

test('id frames literals at either end of a list without counting its parentheses', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '+ Ready for field',
        '+ Ready for value',
        '* ID NIL',
        'TAG1 OK ID completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $connection->id(["na\nme" => "v\n2"]);

    $stream->assertWritten('TAG1 ID ({5}');
    $stream->assertWritten("na\nme {3}");
    $stream->assertWritten("v\n2)");
});

test('append always sends an exact message literal', function (string $message) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '+ Ready for message',
        'TAG1 OK APPEND completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $connection->append('INBOX', $message);

    $stream->assertWritten('TAG1 APPEND "INBOX" {'.strlen($message)."}\r\n");
    $stream->assertWritten($message."\r\n");
})->with(['', 'A "quoted" message with a \\ slash', 'Bonjour été']);

test('fetch expands macros into valid data item lists', function (string $macro, string $items, string $extra) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* 1 FETCH (FLAGS () INTERNALDATE "02-Sep-2026 10:00:00 +0000" RFC822.SIZE 20'.$extra.')',
        '* 2 FETCH (FLAGS (\\Seen))',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $result = $connection->fetch('1:*', $macro, identifier: ImapIdentifier::MessageNumber);

    $stream->assertWritten("TAG1 FETCH 1:* ($items)");
    expect($result->messages())->toHaveCount(1);
    expect($result->messages()[0]->size())->toBe(20);
})->with([
    ['ALL', 'FLAGS INTERNALDATE RFC822.SIZE ENVELOPE', ' ENVELOPE NIL'],
    ['fast', 'FLAGS INTERNALDATE RFC822.SIZE', ''],
    ['FULL', 'FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODY', ' ENVELOPE NIL BODY NIL'],
]);

test('sequence fetch matches response attributes for peek and partial requests', function (string $request, string $attribute) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* 1 FETCH ('.$attribute.' {5}',
        'hello)',
        '* 2 FETCH (FLAGS (\\Seen))',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $result = $connection->fetch(1, $request, identifier: ImapIdentifier::MessageNumber);

    $stream->assertWritten("TAG1 FETCH 1 ($request)");
    expect($result->messages())->toHaveCount(1);
    expect($result->messages()[0]->get($attribute))->toBe('hello');
})->with([
    ['BODY.PEEK[TEXT]', 'BODY[TEXT]'],
    ['body.peek[header]', 'BODY[HEADER]'],
    ['BODY.PEEK[TEXT]<0.5>', 'BODY[TEXT]<0>'],
    ['BODY[1]<10.5>', 'BODY[1]<10>'],
    ['BINARY.PEEK[1]<10.5>', 'BINARY[1]<10>'],
    ['BODY.PEEK[HEADER.FIELDS (SUBJECT FROM)]', 'BODY[HEADER.FIELDS (SUBJECT FROM)]'],
]);

test('message operations accept raw sequence sets', function (string $method, array $arguments, string $command) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $connection->{$method}('1:3,7:*', ...$arguments);

    $stream->assertWritten("TAG1 $command");
})->with([
    ['fetch', ['FLAGS'], 'UID FETCH 1:3,7:* (FLAGS)'],
    ['store', ['\\Seen'], 'UID STORE 1:3,7:* +FLAGS.SILENT (\\Seen)'],
    ['copy', ['Archive'], 'UID COPY 1:3,7:* "Archive"'],
    ['move', ['Archive'], 'UID MOVE 1:3,7:* "Archive"'],
    ['expunge', [], 'UID EXPUNGE 1:3,7:*'],
]);

test('search accepts an explicit charset separately from criteria', function (ImapIdentifier $identifier, string $command) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* SEARCH 7',
        'TAG1 OK SEARCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $connection->search(['SUBJECT', '"été"'], 'UTF-8', $identifier);

    $stream->assertWritten('TAG1 '.$command.' CHARSET "UTF-8" SUBJECT "été"');
})->with([
    [ImapIdentifier::Uid, 'UID SEARCH'],
    [ImapIdentifier::MessageNumber, 'SEARCH'],
]);

test('sort accepts an explicit charset separately from criteria', function (ImapIdentifier $identifier, string $command) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* SORT 7',
        'TAG1 OK SORT completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $connection->sort(new ImapSort(new SortCriterion(ImapSortKey::Arrival)), ['ALL'], 'US-ASCII', $identifier);

    $stream->assertWritten('TAG1 '.$command.' (ARRIVAL) US-ASCII ALL');
})->with([
    [ImapIdentifier::Uid, 'UID SORT'],
    [ImapIdentifier::MessageNumber, 'SORT'],
]);

test('list supports selection options multiple patterns and status return data', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* LIST (\\Subscribed) "/" "INBOX"',
        '* STATUS "INBOX" (MESSAGES 5 UNSEEN 2)',
        'TAG1 OK LIST completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $responses = $connection->list(
        pattern: ['INBOX', 'Shared/*'],
        selection: ['SUBSCRIBED', 'RECURSIVEMATCH'],
        return: ['CHILDREN', 'STATUS', ['MESSAGES', 'UNSEEN']],
    );

    $stream->assertWritten('TAG1 LIST (SUBSCRIBED RECURSIVEMATCH) "" ("INBOX" "Shared/*") RETURN (CHILDREN STATUS (MESSAGES UNSEEN))');
    expect($responses)->toHaveCount(2);
    expect($responses->last()->type()->is('STATUS'))->toBeTrue();
});

test('folder listing ignores additional untagged responses', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        '* LIST () "/" "INBOX"',
        '* STATUS "INBOX" (MESSAGES 5)',
        'TAG2 OK LIST completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    $folders = $mailbox->folders()->get();

    expect($folders)->toHaveCount(1);
    expect($folders->first()->path())->toBe('INBOX');
});

test('status accepts explicitly requested items including rev1 recent', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* STATUS "INBOX" (RECENT 2)',
        'TAG1 OK STATUS completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $connection->status(items: ['RECENT']);

    $stream->assertWritten('TAG1 STATUS "INBOX" (RECENT)');
});

test('quick resync includes paired sequence matches', function (array|int|string $knownUids, string $expected) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK SELECT completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $connection->select('INBOX', new QuickResync(777, 42, $knownUids, [[1, 2, 3], [5, 6, 9]]));

    $stream->assertWritten('TAG1 SELECT "INBOX" (QRESYNC (777 42'.$expected.' (1:3 5:6,9)))');
})->with([
    [[], ''],
    [[5, 6, 9], ' 5:6,9'],
    ['5:6,9', ' 5:6,9'],
]);

test('uid fetch requires both uid and requested attributes while preserving raw updates', function (string $request, string $attribute) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* 1 FETCH (UID 7 MODSEQ (43))',
        '* 1 FETCH (UID 7 FLAGS (\\Seen) MODSEQ (44))',
        '* 1 FETCH ('.$attribute.' "missing uid")',
        '* 1 FETCH (UID 7 '.$attribute.' "content" MODSEQ (45))',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $result = $connection->fetch(7, $request);

    expect($result->messages())->toHaveCount(1);
    expect($result->messages()[0]->get($attribute))->toBe('content');
    expect($result->responses()->untagged())->toHaveCount(4);
})->with([
    ['BODY.PEEK[1.2]', 'BODY[1.2]'],
    ['BODY.PEEK[TEXT]<0.7>', 'BODY[TEXT]<0>'],
    ['BINARY.PEEK[1]<10.7>', 'BINARY[1]<10>'],
]);
