<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Number;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Idle\Events\FolderSelected;
use DirectoryTree\ImapEngine\Idle\Events\MessagesExist;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\Selection\Result;
use Tests\Support\ScriptedFolder;
use Tests\Support\ScriptedMailbox;

test('idle tracks arrivals across count notifications', function (?int $uidNext, int $notifications, array $responses, array $expected) {
    $connection = ImapConnection::fake(['* OK Welcome', 'TAG1 OK LOGIN completed', ...$responses]);
    $mailbox = new ScriptedMailbox;
    $mailbox->connect($connection);
    $folder = new ScriptedFolder($mailbox, 'INBOX', [
        new FolderSelected('INBOX', new Result(uidValidity: 1, uidNext: $uidNext)),
        ...array_fill(0, $notifications, new MessagesExist('INBOX', new UntaggedResponse([
            new Atom('*'), new Number('3'), new Atom('EXISTS'),
        ]))),
    ]);
    $received = [];

    $folder->idle(function (MessageInterface $message) use (&$received) {
        $received[] = $message->uid();
    });

    expect($received)->toBe($expected);
    $connection->stream()->assertNotWritten('BODY[');
    $connection->disconnect();
})->with([
    'multiple arrivals and duplicate count notification' => [7, 2, [
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 7 9', 'TAG3 OK SEARCH completed',
        '* 3 FETCH (UID 9 FLAGS ())', '* 2 FETCH (UID 7 FLAGS ())', 'TAG4 OK FETCH completed',
        '* OK [UIDVALIDITY 1]', 'TAG5 OK SELECT completed',
        '* SEARCH 9', 'TAG6 OK SEARCH completed',
        '* 3 FETCH (UID 9 FLAGS ())', 'TAG7 OK FETCH completed',
    ], [7, 9]],
    'message removed before fetch' => [7, 1, [
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 7', 'TAG3 OK SEARCH completed', 'TAG4 OK FETCH completed',
    ], []],
    'application connection has different uid validity' => [7, 1, [
        '* OK [UIDVALIDITY 2]', 'TAG2 OK SELECT completed',
    ], []],
    'missing uidnext snapshots existing messages' => [null, 1, [
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 2 6', 'TAG3 OK SEARCH completed',
        '* OK [UIDVALIDITY 1]', 'TAG4 OK SELECT completed',
        '* SEARCH 7', 'TAG5 OK SEARCH completed',
        '* 3 FETCH (UID 7 FLAGS ())', 'TAG6 OK FETCH completed',
    ], [7]],
    'initially empty folder delivers its first arrival' => [1, 1, [
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 1', 'TAG3 OK SEARCH completed',
        '* 1 FETCH (UID 1 FLAGS ())', 'TAG4 OK FETCH completed',
    ], [1]],
]);

test('idle reconciles a reconnect selection before waiting for another notification', function (int $validity, array $expected) {
    $connection = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 7', 'TAG3 OK SEARCH completed',
        '* 1 FETCH (UID 7 FLAGS ())', 'TAG4 OK FETCH completed',
    ]);
    $mailbox = new ScriptedMailbox;
    $mailbox->connect($connection);
    $folder = new ScriptedFolder($mailbox, 'INBOX', [
        new FolderSelected('INBOX', new Result(uidValidity: 1, uidNext: 7)),
        new FolderSelected('INBOX', new Result(uidValidity: $validity, uidNext: 8)),
        ...($validity === 1 ? [new MessagesExist('INBOX', new UntaggedResponse([
            new Atom('*'), new Number('1'), new Atom('EXISTS'),
        ]))] : []),
    ]);
    $received = [];

    $folder->idle(function (MessageInterface $message) use (&$received) {
        $received[] = $message->uid();

        return false;
    });

    expect($received)->toBe($expected);
    $connection->disconnect();
})->with([
    'unchanged validity delivers pending arrivals and honors stop' => [1, [7]],
    'changed validity starts a new arrival baseline' => [2, []],
]);

test('idle retries a lost application connection and respects uid validity', function (array $beforeDisconnect, int $validity, array $expected) {
    $stream = (new FakeStream)->disconnectWhenEmpty();
    $stream->feed(['* OK Welcome', 'TAG1 OK LOGIN completed', ...$beforeDisconnect]);
    $first = new ImapConnection($stream);
    $second = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* OK [UIDVALIDITY '.$validity.']', 'TAG2 OK SELECT completed',
        '* SEARCH 7', 'TAG3 OK SEARCH completed',
        '* 1 FETCH (UID 7 FLAGS ())', 'TAG4 OK FETCH completed',
    ]);
    $mailbox = new ScriptedMailbox($first, $second);
    $mailbox->connect();
    $folder = new ScriptedFolder($mailbox, 'INBOX', [
        new FolderSelected('INBOX', new Result(uidValidity: 1, uidNext: 7)),
        new MessagesExist('INBOX', new UntaggedResponse([
            new Atom('*'), new Number('1'), new Atom('EXISTS'),
        ])),
    ]);
    $received = [];

    $folder->idle(function (MessageInterface $message) use (&$received) {
        $received[] = $message->uid();
    });

    expect($received)->toBe($expected);
    expect($mailbox->connection())->toBe($second);
    $second->disconnect();
})->with([
    'lost during select' => [[], 1, [7]],
    'lost during search' => [['* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed'], 1, [7]],
    'lost during fetch' => [['* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed', '* SEARCH 7', 'TAG3 OK SEARCH completed'], 1, [7]],
    'changed uid validity' => [[], 2, []],
]);

test('idle propagates callback connection exceptions without reconnecting', function () {
    $connection = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* OK [UIDVALIDITY 1]', 'TAG2 OK SELECT completed',
        '* SEARCH 7', 'TAG3 OK SEARCH completed',
        '* 1 FETCH (UID 7 FLAGS ())', 'TAG4 OK FETCH completed',
    ]);
    $mailbox = new ScriptedMailbox;
    $mailbox->connect($connection);
    $folder = new ScriptedFolder($mailbox, 'INBOX', [
        new FolderSelected('INBOX', new Result(uidValidity: 1, uidNext: 7)),
        new MessagesExist('INBOX', new UntaggedResponse([
            new Atom('*'), new Number('1'), new Atom('EXISTS'),
        ])),
    ]);
    $exception = new ImapConnectionClosedException('Application callback failed');

    expect(fn () => $folder->idle(function () use ($exception) {
        throw $exception;
    }))->toThrow($exception);
    expect($mailbox->connection())->toBe($connection);
    $connection->stream()->assertNotWritten('LOGOUT');
    $connection->disconnect();
});
