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

test('it can determine if two messages are the same', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
    ]));

    $folder1 = new Folder($mailbox, 'INBOX', [], '/');
    $folder2 = new Folder($mailbox, 'INBOX.Sent', [], '/');

    // Create messages with different properties
    $message1 = new Message($folder1, 1, [], 'header1', 'body1');
    $message2 = new Message($folder1, 1, [], 'header1', 'body1'); // Same as message1
    $message3 = new Message($folder1, 2, [], 'header1', 'body1'); // Different UID
    $message4 = new Message($folder2, 1, [], 'header1', 'body1'); // Different folder
    $message5 = new Message($folder1, 1, [], 'header2', 'body1'); // Different header
    $message6 = new Message($folder1, 1, [], 'header1', 'body2'); // Different body

    // Same message
    expect($message1->is($message2))->toBeTrue();

    // Different header
    expect($message1->is($message5))->toBeTrue();

    // Different body
    expect($message1->is($message6))->toBeTrue();

    // Different UID
    expect($message1->is($message3))->toBeFalse();

    // Different folder
    expect($message1->is($message4))->toBeFalse();
});

test('it serializes and unserializes the message correctly', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $originalMessage = new Message(
        $folder,
        123,
        ['\\Seen', '\\Flagged'],
        'From: test@example.com',
        'This is the message body content',
        1024
    );

    $serialized = serialize($originalMessage);
    $unserializedMessage = unserialize($serialized);

    expect($unserializedMessage->uid())->toBe(123);
    expect($unserializedMessage->flags())->toBe(['\\Seen', '\\Flagged']);
    expect($unserializedMessage->head())->toBe('From: test@example.com');
    expect($unserializedMessage->body())->toBe('This is the message body content');
    expect($unserializedMessage->size())->toBe(1024);
});
