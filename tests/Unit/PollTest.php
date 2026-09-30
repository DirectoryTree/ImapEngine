<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\Exception;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionClosedException;
use DirectoryTree\ImapEngine\Poll;
use Tests\Support\ScriptedMailbox;

test('poll propagates callback exceptions without reconnecting', function (string $exceptionClass) {
    $connection = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        '* OK [UIDNEXT 1]', 'TAG3 OK SELECT completed',
        '* LIST () "/" "INBOX"', 'TAG4 OK LIST completed',
        '* OK [UIDNEXT 2]', 'TAG5 OK SELECT completed',
        '* SEARCH 1', 'TAG6 OK SEARCH completed',
        '* 1 FETCH (UID 1 FLAGS ())', 'TAG7 OK FETCH completed',
        '* BYE Logging out', 'TAG8 OK LOGOUT completed',
    ]);
    $mailbox = new ScriptedMailbox($connection);
    $exception = new $exceptionClass('Callback failed');

    expect(fn () => (new Poll($mailbox, 'INBOX', 1))->start(function () use ($exception) {
        throw $exception;
    }, fn ($query) => $query))->toThrow($exception);

    expect($mailbox->connected())->toBeFalse();
    expect($connection->stream()->opened())->toBeFalse();
    $connection->stream()->assertWritten('TAG8 LOGOUT');
})->with([RuntimeException::class, Exception::class, ImapConnectionClosedException::class]);

test('poll still reconnects when retrieving messages loses the connection', function () {
    $first = (new FakeStream)->disconnectWhenEmpty()->feed([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        '* OK [UIDVALIDITY 1]', '* OK [UIDNEXT 1]', 'TAG3 OK SELECT completed',
        '* LIST () "/" "INBOX"', 'TAG4 OK LIST completed',
        '* OK [UIDVALIDITY 1]', 'TAG5 OK SELECT completed',
    ]);
    $second = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        '* OK [UIDVALIDITY 1]', '* OK [UIDNEXT 2]', 'TAG3 OK SELECT completed',
        '* LIST () "/" "INBOX"', 'TAG4 OK LIST completed',
        '* OK [UIDVALIDITY 1]', 'TAG5 OK SELECT completed',
        '* SEARCH 1', 'TAG6 OK SEARCH completed',
        '* 1 FETCH (UID 1 FLAGS ())', 'TAG7 OK FETCH completed',
        '* BYE Logging out', 'TAG8 OK LOGOUT completed',
    ]);
    $mailbox = new ScriptedMailbox(new ImapConnection($first), $second);
    $received = [];

    (new Poll($mailbox, 'INBOX', 1))->start(function ($message) use (&$received) {
        $received[] = $message->uid();

        return false;
    }, fn ($query) => $query);

    expect($received)->toBe([1]);
    expect($mailbox->connected())->toBeFalse();
    $first->assertWritten('TAG6 UID SEARCH UID 1:*');
    $second->stream()->assertWritten('TAG6 UID SEARCH UID 1:*');
    expect($second->stream()->opened())->toBeFalse();
});

test('poll stops and disconnects immediately when the callback returns false', function () {
    $connection = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        '* OK [UIDNEXT 1]', 'TAG3 OK SELECT completed',
        '* LIST () "/" "INBOX"', 'TAG4 OK LIST completed',
        '* OK [UIDNEXT 3]', 'TAG5 OK SELECT completed',
        '* SEARCH 1 2', 'TAG6 OK SEARCH completed',
        '* 1 FETCH (UID 1 FLAGS ())', '* 2 FETCH (UID 2 FLAGS ())', 'TAG7 OK FETCH completed',
        '* BYE Logging out', 'TAG8 OK LOGOUT completed',
    ]);
    $mailbox = new ScriptedMailbox($connection);
    $received = [];

    (new Poll($mailbox, 'INBOX', 60))->start(function ($message) use (&$received) {
        $received[] = $message->uid();

        return false;
    }, fn ($query) => $query);

    expect($received)->toBe([1]);
    expect($mailbox->connected())->toBeFalse();
    expect($connection->stream()->opened())->toBeFalse();
    $connection->stream()->assertWritten('TAG8 LOGOUT');
});

test('poll preserves or resets its uid baseline when selecting again', function (int $validity, int $uidNext, array $expected) {
    $connection = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* LIST () "/" "INBOX"', 'TAG2 OK LIST completed',
        '* OK [UIDVALIDITY 1]', '* OK [UIDNEXT 1]', 'TAG3 OK SELECT completed',
        '* LIST () "/" "INBOX"', 'TAG4 OK LIST completed',
        '* OK [UIDVALIDITY 1]', '* OK [UIDNEXT 4]', 'TAG5 OK SELECT completed',
        '* SEARCH 3 1 2', 'TAG6 OK SEARCH completed',
        '* 3 FETCH (UID 3 FLAGS ())', '* 1 FETCH (UID 1 FLAGS ())', '* 2 FETCH (UID 2 FLAGS ())', 'TAG7 OK FETCH completed',
        '* LIST () "/" "INBOX"', 'TAG8 OK LIST completed',
        "* OK [UIDVALIDITY {$validity}]", "* OK [UIDNEXT {$uidNext}]", 'TAG9 OK SELECT completed',
        '* SEARCH 1 3 4', 'TAG10 OK SEARCH completed',
        '* 1 FETCH (UID 1 FLAGS ())', '* 3 FETCH (UID 3 FLAGS ())', '* 4 FETCH (UID 4 FLAGS ())', 'TAG11 OK FETCH completed',
        '* BYE Logging out', 'TAG12 OK LOGOUT completed',
    ]);
    $mailbox = new ScriptedMailbox($connection);
    $received = [];

    (new Poll($mailbox, 'INBOX', 1))->start(function ($message) use (&$received) {
        $received[] = $message->uid();

        return $message->uid() !== 4;
    }, fn ($query) => $query);

    expect($received)->toBe([1, 2, 3, ...$expected]);
    $start = $validity === 1 ? 4 : $uidNext;
    $connection->stream()->assertWritten("TAG10 UID SEARCH UID {$start}:*");
    expect($connection->stream()->opened())->toBeFalse();
})->with([
    'same validity excludes delivered and older UIDs' => [1, 5, [4]],
    'changed validity starts a new baseline' => [2, 3, [3, 4]],
]);
