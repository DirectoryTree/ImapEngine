<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;

test('it moves message using MOVE when capable and returns the new UID', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 STARTTLS MOVE AUTH=PLAIN',
        'TAG2 OK CAPABILITY completed',
        'TAG3 OK [COPYUID 1234567890 1 42] MOVE completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'header', 'body');

    $newUid = $message->move('INBOX.Sent');

    expect($newUid)->toBe(42);
});

test('it copies and then deletes message using UIDPLUS when incapable of MOVE and returns the new UID', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 STARTTLS UIDPLUS AUTH=PLAIN',
        'TAG2 OK CAPABILITY completed',
        'TAG3 OK [COPYUID 1234567890 1 123] COPY completed',
        'TAG4 OK STORE completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'header', 'body');

    $newUid = $message->move('INBOX.Sent');

    expect($newUid)->toBe(123);
});

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
