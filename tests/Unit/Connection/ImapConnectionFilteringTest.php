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
    expect($result->messages()[0]->flags())->toBe(['\\Seen']);
    expect($result->responses())->toHaveCount(3);
})->with(['fetch', 'store']);

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
