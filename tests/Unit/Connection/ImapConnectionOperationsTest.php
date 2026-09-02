<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Enums\ImapIdentifier;
use DirectoryTree\ImapEngine\Enums\ImapSortKey;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\ImapSort;
use DirectoryTree\ImapEngine\SortCriterion;
use DirectoryTree\ImapEngine\Store\UnchangedSince;
use DirectoryTree\ImapEngine\StoreModifier;
use DirectoryTree\ImapEngine\StoreResult;

test('store supports adding removing and replacing flags', function (?string $mode, bool $silent, string $item) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK STORE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->store('\\Seen', [1, 2, 4], mode: $mode, silent: $silent);

    $stream->assertWritten("TAG1 UID STORE 1:2,4 $item (\\Seen)");
    expect($result)->toBeInstanceOf(StoreResult::class);
    expect($result->successful())->toBeTrue();
})->with([
    'add' => ['+', false, '+FLAGS'],
    'remove' => ['-', false, '-FLAGS'],
    'replace' => [null, false, 'FLAGS'],
    'add silently' => ['+', true, '+FLAGS.SILENT'],
    'remove silently' => ['-', true, '-FLAGS.SILENT'],
    'replace silently' => [null, true, 'FLAGS.SILENT'],
]);

test('non silent store retains fetched messages and raw responses', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 7 FLAGS (\\Seen))',
        'TAG1 OK STORE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->store('\\Seen', 7, silent: false);

    $stream->assertWritten('TAG1 UID STORE 7 +FLAGS (\\Seen)');
    expect($result->messages())->toHaveCount(1);
    expect($result->messages()[0]->uid())->toBe(7);
    expect($result->messages()[0]->flags())->toBe(['\\Seen']);
    expect($result->responses())->toHaveCount(2);
    expect($result->modified())->toBe([]);
});

test('silent store retains returned modification sequences', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 7 MODSEQ (44))',
        'TAG1 OK STORE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->store('\\Seen', 7);

    $stream->assertWritten('TAG1 UID STORE 7 +FLAGS.SILENT (\\Seen)');
    expect($result->messages()[0]->modSequence())->toBe(44);
});

test('conditional store returns conflicting message numbers', function (string $status) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (FLAGS (\\Seen) MODSEQ (44))',
        "TAG1 $status [MODIFIED 2:3] Conditional STORE completed",
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->store(
        '\\Seen', 1, 3, mode: null, silent: false,
        identifier: ImapIdentifier::MessageNumber,
        modifiers: new UnchangedSince(43),
    );

    $stream->assertWritten('TAG1 STORE 1:3 (UNCHANGEDSINCE 43) FLAGS (\\Seen)');
    expect($result->modified())->toBe([2, 3]);
    expect($result->messages()[0]->flags())->toBe(['\\Seen']);
    expect($result->messages()[0]->modSequence())->toBe(44);
    expect($result->successful())->toBe($status === 'OK');
})->with(['OK', 'NO']);

test('store rejects failures that are not conditional conflicts', function (string $response) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        $response,
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    expect(fn () => $connection->store('\\Seen', 7))
        ->toThrow(ImapCommandException::class);
})->with([
    'TAG1 NO Permission denied',
    'TAG1 BAD Invalid arguments',
    'TAG1 BAD [MODIFIED 7] Invalid arguments',
]);

test('store combines modifiers in one list and preserves a zero checkpoint', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK [MODIFIED 7] Conditional STORE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $custom = new class implements StoreModifier
    {
        public function toImap(): string
        {
            return 'X-CUSTOM';
        }
    };

    $result = $connection->store(
        '\\Seen', 7, null, '+', true, null, ImapIdentifier::Uid,
        new UnchangedSince(0), $custom,
    );

    $stream->assertWritten('TAG1 UID STORE 7 (UNCHANGEDSINCE 0 X-CUSTOM) +FLAGS.SILENT (\\Seen)');
    expect($result->modified())->toBe([7]);
});

test('search supports both identifier types without changing search criteria', function (ImapIdentifier $identifier, string $command) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        '* SEARCH 2 3',
        'TAG1 OK SEARCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->search(['UID 7:9'], identifier: $identifier);

    $stream->assertWritten("TAG1 $command UID 7:9");
    expect((string) $response)->toBe('* SEARCH 2 3');
})->with([
    [ImapIdentifier::Uid, 'UID SEARCH'],
    [ImapIdentifier::MessageNumber, 'SEARCH'],
]);

test('sort supports both identifier types', function (ImapIdentifier $identifier, string $command) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        '* SORT 3 2',
        'TAG1 OK SORT completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $sort = new ImapSort(new SortCriterion(ImapSortKey::Arrival));

    $response = $connection->sort($sort, ['ALL'], identifier: $identifier);

    $stream->assertWritten("TAG1 $command (ARRIVAL) UTF-8 ALL");
    expect((string) $response)->toBe('* SORT 3 2');
})->with([
    [ImapIdentifier::Uid, 'UID SORT'],
    [ImapIdentifier::MessageNumber, 'SORT'],
]);

test('copy and move support both identifier types', function (string $method, ImapIdentifier $identifier, string $command) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        "TAG1 OK $command completed",
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->{$method}('Archive', 1, 3, identifier: $identifier);

    $stream->assertWritten("TAG1 $command 1:3 \"Archive\"");
    expect($response->successful())->toBeTrue();
})->with([
    ['copy', ImapIdentifier::Uid, 'UID COPY'],
    ['copy', ImapIdentifier::MessageNumber, 'COPY'],
    ['move', ImapIdentifier::Uid, 'UID MOVE'],
    ['move', ImapIdentifier::MessageNumber, 'MOVE'],
]);

test('get quota sends the matching command', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        '* QUOTA "root" (STORAGE 10 100)',
        'TAG1 OK GETQUOTA completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->getQuota('root');

    $stream->assertWritten('TAG1 GETQUOTA "root"');
    expect((string) $response)->toBe('* QUOTA "root" (STORAGE 10 100)');
});

test('get quota root preserves the mailbox mapping and each quota response', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        '* QUOTAROOT "INBOX" "root" "other"',
        '* QUOTA "root" (STORAGE 10 100)',
        '* QUOTA "other" (MESSAGE 2 20)',
        '* 5 EXISTS',
        'TAG1 OK GETQUOTAROOT completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->getQuotaRoot('INBOX');

    $stream->assertWritten('TAG1 GETQUOTAROOT "INBOX"');
    expect($responses)->toHaveCount(3);
    expect((string) $responses->first())->toBe('* QUOTAROOT "INBOX" "root" "other"');
    expect((string) $responses->last())->toBe('* QUOTA "other" (MESSAGE 2 20)');
});

test('get quota root preserves a mailbox with no quota roots', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        '* QUOTAROOT "INBOX"',
        'TAG1 OK GETQUOTAROOT completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->getQuotaRoot('INBOX');

    expect($responses)->toHaveCount(1);
    expect((string) $responses->first())->toBe('* QUOTAROOT "INBOX"');
});

test('logout closes the local connection when the server closes before completion', function () {
    $stream = new FakeStream;
    $stream->feed('* OK Welcome to IMAP');

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $stream->setMeta('eof', true);

    expect(fn () => $connection->logout())->toThrow(ImapConnectionClosedException::class);
    expect($connection->connected())->toBeFalse();
    $stream->assertWritten('TAG1 LOGOUT');
});
