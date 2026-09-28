<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageData;

test('message cursors fetch bounded batches lazily without changing query pagination', function (bool $stopEarly) {
    $connection = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed', 'TAG2 OK SELECT completed',
        '* SEARCH 3 1 2', 'TAG3 OK SEARCH completed',
        '* 2 FETCH (UID 2 FLAGS ())', '* 1 FETCH (UID 1 FLAGS ())', 'TAG4 OK FETCH completed',
        '* 3 FETCH (UID 3 FLAGS ())', 'TAG5 OK FETCH completed',
    ]);
    $mailbox = new Mailbox;
    $mailbox->connect($connection);
    $query = (new Folder($mailbox, 'INBOX'))->messages()->with(MessageData::flags())->orderByUid()->limit(1, 3);
    $cursor = $query->cursor(2);
    $connection->stream()->assertNotWritten('SEARCH');
    $received = [];

    foreach ($cursor as $key => $message) {
        $received[$key] = $message->uid();
        if ($stopEarly) {
            break;
        }
    }

    expect($received)->toBe($stopEarly ? [1] : [1, 2, 3]);
    expect($query->getLimit())->toBe(1);
    expect($query->getPage())->toBe(3);
    $connection->stream()->assertWritten('UID FETCH 1:2');
    if ($stopEarly) {
        $connection->stream()->assertNotWritten('UID FETCH 3');
    } else {
        $connection->stream()->assertWritten('UID FETCH 3');
    }
    $connection->disconnect();
})->with(['all batches' => false, 'early stop' => true]);
