<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Enums\ImapIdentifier;

test('fetch and store exclude updates outside the requested message set', function (array|int|string $set, string $command, ImapIdentifier $identifier) {
    $uid = $identifier === ImapIdentifier::Uid ? 7 : 70;
    $number = $identifier === ImapIdentifier::Uid ? 3 : 7;
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* 99 FETCH (FLAGS (\\Answered))',
        '* 8 FETCH (UID 8 FLAGS (\\Flagged))',
        "* $number FETCH (UID $uid FLAGS (\\Seen))",
        'TAG1 OK Completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $command === 'fetch'
        ? $connection->fetch($set, 'FLAGS', identifier: $identifier)
        : $connection->store($set, '\\Seen', silent: false, identifier: $identifier);

    expect($result->messages())->toHaveCount(1);
    expect($result->messages()[0]->uid())->toBe($uid);
    expect($result->messages()[0]->flags())->toBe(['\\Seen']);
    expect($result->responses())->toHaveCount(4);
    expect((string) $result->responses()->untagged()->all()[1])->toBe('* 8 FETCH (UID 8 FLAGS (\\Flagged))');
})->with([
    'integer' => [7],
    'array' => [[1, 7, 9]],
    'string' => ['7'],
    'ascending range' => ['5:7'],
    'descending range' => ['7:5'],
    'mixed ranges' => ['1,5:7,9'],
])->with(['fetch', 'store'])->with([ImapIdentifier::Uid, ImapIdentifier::MessageNumber]);

test('sequence addressed results do not require a uid', function (string $command) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* 8 FETCH (FLAGS (\\Flagged))',
        '* 7 FETCH (FLAGS (\\Seen))',
        'TAG1 OK Completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $command === 'fetch'
        ? $connection->fetch(7, 'FLAGS', identifier: ImapIdentifier::MessageNumber)
        : $connection->store(7, '\\Seen', identifier: ImapIdentifier::MessageNumber);

    expect($result->messages())->toHaveCount(1);
    expect($result->messages()[0]->sequenceNumber())->toBe(7);
    expect($result->messages()[0]->flags())->toBe(['\\Seen']);
    expect($result->responses())->toHaveCount(3);
})->with(['fetch', 'store']);

test('fetch and store filter large sparse message sets', function (string $command) {
    $set = range(1, 1999, 2);
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* 1 FETCH (UID 1 FLAGS (\\Seen))',
        '* 2 FETCH (UID 1000 FLAGS (\\Flagged))',
        '* 3 FETCH (UID 1001 FLAGS (\\Seen))',
        '* 4 FETCH (UID 1999 FLAGS (\\Seen))',
        'TAG1 OK Completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $command === 'fetch'
        ? $connection->fetch($set, 'FLAGS')
        : $connection->store($set, '\\Seen', silent: false);

    expect(array_map(fn ($message) => $message->uid(), $result->messages()))->toBe([1, 1001, 1999]);
})->with(['fetch', 'store']);

test('fetch and store filter overlapping message ranges', function (string $command, ImapIdentifier $identifier) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* 4 FETCH (UID 4 FLAGS (\\Flagged))',
        '* 7 FETCH (UID 7 FLAGS (\\Seen))',
        '* 10 FETCH (UID 10 FLAGS (\\Seen))',
        '* 17 FETCH (UID 17 FLAGS (\\Flagged))',
        '* 19 FETCH (UID 19 FLAGS (\\Seen))',
        'TAG1 OK Completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $command === 'fetch'
        ? $connection->fetch('10:5,1:7,20:18', 'FLAGS', identifier: $identifier)
        : $connection->store('10:5,1:7,20:18', '\\Seen', silent: false, identifier: $identifier);

    expect(array_map(fn ($message) => $message->uid(), $result->messages()))->toBe([4, 7, 10, 19]);
})->with(['fetch', 'store'])->with([ImapIdentifier::Uid, ImapIdentifier::MessageNumber]);

test('server resolved sets do not discard potentially requested messages', function (string $set, string $command) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '* 3 FETCH (UID 7 FLAGS (\\Seen))',
        'TAG1 OK Completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $command === 'fetch'
        ? $connection->fetch($set, 'FLAGS')
        : $connection->store($set, '\\Seen');

    expect($result->messages())->toHaveCount(1);
    expect($result->messages()[0]->uid())->toBe(7);
})->with(['*', '999:*', '*:999', '$', '1:4294967295'])->with(['fetch', 'store']);
