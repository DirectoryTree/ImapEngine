<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;

test('it moves message using MOVE when capable', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 STARTTLS MOVE AUTH=PLAIN',
        'TAG2 OK CAPABILITY completed',
        'TAG3 OK MOVE completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'header', 'body');

    $message->move('INBOX.Sent');
})->throwsNoExceptions();

test('it copies and then deletes message using UIDPLUS when incapable of MOVE', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 STARTTLS UIDPLUS AUTH=PLAIN',
        'TAG2 OK CAPABILITY completed',
        'TAG3 OK UID MOVE completed',
        'TAG4 OK COPY completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'header', 'body');

    $message->move('INBOX.Sent');
})->throwsNoExceptions();

test('it throws exception when server does not support MOVE or UIDPLUS capabilities', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 STARTTLS AUTH=PLAIN',
        'TAG2 OK CAPABILITY completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'header', 'body');

    $message->move('INBOX.Sent');
})->throws(ImapCapabilityException::class);

test('it can mark and unmark a message as flagged', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 STARTTLS AUTH=PLAIN',
        'TAG2 OK CAPABILITY completed',
        'TAG3 OK STORE completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'header', 'body');

    expect($message->isFlagged())->toBeFalse();
    expect($message->flags())->not->toContain('\\Flagged');

    $message->markFlagged();

    expect($message->isFlagged())->toBeTrue();
    expect($message->flags())->toContain('\\Flagged');
    expect($message->hasFlag(ImapFlag::Flagged))->toBeTrue();

    $message->unmarkFlagged();

    expect($message->isFlagged())->toBeFalse();
    expect($message->flags())->not->toContain('\\Flagged');
    expect($message->hasFlag(ImapFlag::Flagged))->toBeFalse();
});
