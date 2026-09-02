<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;

test('it properly decodes name from UTF-7', function () {
    $mailbox = Mailbox::make();

    // Create a folder with a UTF-7 encoded name.
    $folder = new Folder(
        mailbox: $mailbox,
        path: '[Gmail]/&BBoEPgRABDcEOAQ9BDA-',
        flags: ['\\HasNoChildren'],
        delimiter: '/'
    );

    // The name should be decoded to UTF-8.
    expect($folder->name())->toBe('Корзина');

    // The path should remain as is (UTF-7 encoded).
    expect($folder->path())->toBe('[Gmail]/&BBoEPgRABDcEOAQ9BDA-');
});

test('it preserves existing UTF-8 characters in folder names', function () {
    $mailbox = Mailbox::make();

    // Create a folder with a name that already contains UTF-8 characters.
    $utf8FolderName = 'Привет';

    $folder = new Folder(
        mailbox: $mailbox,
        path: '[Gmail]/'.$utf8FolderName,
        flags: ['\\HasNoChildren'],
        delimiter: '/'
    );

    // The name should remain unchanged
    expect($folder->name())->toBe($utf8FolderName);

    // Test with a mix of UTF-8 characters from different languages.
    $mixedUtf8FolderName = 'Привет_你好_こんにちは';

    $mixedFolder = new Folder(
        mailbox: $mailbox,
        path: '[Gmail]/'.$mixedUtf8FolderName,
        flags: ['\\HasNoChildren'],
        delimiter: '/'
    );

    // The name should remain unchanged.
    expect($mixedFolder->name())->toBe($mixedUtf8FolderName);
});

test('it returns quota data for the mailbox', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
        '* CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS SORT IDLE MOVE QUOTA',
        'TAG3 OK CAPABILITY completed',
        '* QUOTA "INBOX" (STORAGE 54 512)',
        '* QUOTA "INBOX" (MESSAGE 12 1024)',
        'TAG4 OK GETQUOTAROOT completed',
    ]));

    expect($mailbox->inbox()->quota())
        ->toBeArray()
        ->toMatchArray([
            'INBOX' => [
                'STORAGE' => [
                    'usage' => 54,
                    'limit' => 512,
                ],
                'MESSAGE' => [
                    'usage' => 12,
                    'limit' => 1024,
                ],
            ],
        ]);
});

test('it returns quota data for the mailbox when there are no quotas', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
        '* CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS SORT IDLE MOVE QUOTA',
        'TAG3 OK CAPABILITY completed',
        'TAG4 OK GETQUOTAROOT completed',
    ]));

    expect($mailbox->inbox()->quota())->toBe([]);
});

test('it returns quota data for the mailbox when there are multiple resources', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
        '* CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS SORT IDLE MOVE QUOTA',
        'TAG3 OK CAPABILITY completed',
        '* QUOTA "FOO" (STORAGE 54 512)',
        '* QUOTA "FOO" (MESSAGE 12 1024)',
        '* QUOTA "BAR" (STORAGE 10 1024)',
        '* QUOTA "BAR" (MESSAGE 5 1024)',
        'TAG4 OK GETQUOTAROOT completed',
    ]));

    expect($mailbox->inbox()->quota())
        ->toBeArray()
        ->toMatchArray([
            'FOO' => [
                'STORAGE' => [
                    'usage' => 54,
                    'limit' => 512,
                ],
                'MESSAGE' => [
                    'usage' => 12,
                    'limit' => 1024,
                ],
            ],
            'BAR' => [
                'STORAGE' => [
                    'usage' => 10,
                    'limit' => 1024,
                ],
                'MESSAGE' => [
                    'usage' => 5,
                    'limit' => 1024,
                ],
            ],
        ]);
});

test('it returns quota data for the mailbox when there are multiple resources in the same list data', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
        '* CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS SORT IDLE MOVE QUOTA',
        'TAG3 OK CAPABILITY completed',
        '* QUOTA "FOO" (STORAGE 54 512 MESSAGE 12 1024)',
        '* QUOTA "BAR" (STORAGE 10 1024 MESSAGE 5 1024)',
        'TAG4 OK GETQUOTAROOT completed',
    ]));

    expect($mailbox->inbox()->quota())
        ->toBeArray()
        ->toMatchArray([
            'FOO' => [
                'STORAGE' => [
                    'usage' => 54,
                    'limit' => 512,
                ],
                'MESSAGE' => [
                    'usage' => 12,
                    'limit' => 1024,
                ],
            ],
            'BAR' => [
                'STORAGE' => [
                    'usage' => 10,
                    'limit' => 1024,
                ],
                'MESSAGE' => [
                    'usage' => 5,
                    'limit' => 1024,
                ],
            ],
        ]);
});

test('it throws an imap capability exception when inspecting quotas when the imap server does not support quotas', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
        '* CAPABILITY IMAP4rev1 LITERAL+ UIDPLUS SORT IDLE MOVE',
        'TAG3 OK CAPABILITY completed',
    ]));

    $mailbox->inbox()->quota();
})->throws(ImapCapabilityException::class);
