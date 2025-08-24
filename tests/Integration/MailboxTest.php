<?php

use DirectoryTree\ImapEngine\Folder;

test('connect', function () {
    $mailbox = mailbox();

    $mailbox->connect();
})->throwsNoExceptions();

test('inbox', function () {
    $mailbox = mailbox();

    expect($mailbox->inbox())->toBeInstanceOf(Folder::class);
});

test('capabilities', function () {
    $mailbox = mailbox();

    expect(array_flip($mailbox->capabilities()))->toHaveKeys([
        'IMAP4rev1',
        'LITERAL+',
        'UIDPLUS',
        'SORT',
        'IDLE',
        'MOVE',
        'QUOTA',
    ]);
});

test('select', function () {
    $mailbox = mailbox();

    $mailbox->select($inbox = $mailbox->inbox());

    expect($mailbox->selected($inbox))->toBeTrue();
});
