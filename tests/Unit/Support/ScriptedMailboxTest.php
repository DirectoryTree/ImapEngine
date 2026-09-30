<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use Tests\Support\ScriptedMailbox;

test('mailbox clones and reconnects consume the same session queue', function () {
    $first = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* BYE Logging out', 'TAG2 OK LOGOUT completed',
    ]);
    $second = ImapConnection::fake(['* OK Welcome', 'TAG1 OK LOGIN completed']);
    $third = ImapConnection::fake(['* OK Welcome', 'TAG1 OK LOGIN completed']);
    $mailbox = new ScriptedMailbox($first, $second, $third);
    $mailbox->connect();
    $mailbox->connect();

    expect($mailbox->connection())->toBe($first);

    $watcher = clone $mailbox;
    $watcher->connect();
    $mailbox->reconnect();

    expect($watcher->connection())->toBe($second);
    expect($mailbox->connection())->toBe($third);
    expect($first->stream()->opened())->toBeFalse();

    $second->disconnect();
    $third->disconnect();

    expect(fn () => $mailbox->reconnect())->toThrow(LogicException::class, 'No scripted connections remain.');
});
