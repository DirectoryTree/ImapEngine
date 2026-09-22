<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;

test('it examines a folder using the typed selection result', function () {
    $mailbox = Mailbox::make();
    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 3 EXISTS',
        '* OK [UIDVALIDITY 777] UIDs valid',
        'TAG2 OK EXAMINE completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX');

    expect($folder->examine())->toBe([
        ['*', '3', 'EXISTS'],
        ['*', 'OK', ['UIDVALIDITY', '777'], 'UIDs', 'valid'],
    ]);
});

test('it properly decodes name from UTF-7', function () {
    $mailbox = Mailbox::make();

    // Create a folder with a UTF-7 encoded name.
    $folder = new Folder(
        mailbox: $mailbox,
        path: '[Gmail]/&BBoEPgRABDcEOAQ9BDA-',
        attributes: ['\\HasNoChildren'],
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
        attributes: ['\\HasNoChildren'],
        delimiter: '/'
    );

    // The name should remain unchanged
    expect($folder->name())->toBe($utf8FolderName);

    // Test with a mix of UTF-8 characters from different languages.
    $mixedUtf8FolderName = 'Привет_你好_こんにちは';

    $mixedFolder = new Folder(
        mailbox: $mailbox,
        path: '[Gmail]/'.$mixedUtf8FolderName,
        attributes: ['\\HasNoChildren'],
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

test('examining a folder invalidates the previous writable selection', function (string $path) {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        '* OK [UIDVALIDITY 100] Valid',
        'TAG2 OK [READ-WRITE] SELECT completed',
        '* OK [UIDVALIDITY 200] Valid',
        'TAG3 OK [READ-ONLY] EXAMINE completed',
        '* OK [UIDVALIDITY 300] Valid',
        'TAG4 OK [READ-WRITE] SELECT completed',
        '* SEARCH 7',
        'TAG5 OK SEARCH completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $inbox = new Folder($mailbox, 'INBOX');
    $examined = new Folder($mailbox, $path);

    expect($inbox->select()->uidValidity())->toBe(100);
    $examined->examine();

    expect($mailbox->selected($inbox))->toBeFalse();
    expect($mailbox->selected($examined))->toBeFalse();
    expect($inbox->messages()->count())->toBe(1);
    expect($inbox->select()->uidValidity())->toBe(300);

    $stream->assertWritten('TAG3 EXAMINE "'.$path.'"');
    $stream->assertWritten('TAG4 SELECT "INBOX"');
    $stream->assertWritten('TAG5 UID SEARCH ALL');
})->with(['Archive', 'INBOX']);

test('failed examination still invalidates the previous selection', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        'TAG2 OK SELECT completed',
        'TAG3 NO Mailbox unavailable',
        '* OK [UIDVALIDITY 300] Valid',
        'TAG4 OK SELECT completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $inbox = new Folder($mailbox, 'INBOX');
    $missing = new Folder($mailbox, 'Missing');

    $inbox->select();
    expect(fn () => $missing->examine())->toThrow(ImapCommandException::class);
    expect($mailbox->selected($inbox))->toBeFalse();
    expect($inbox->select()->uidValidity())->toBe(300);

    $stream->assertWritten('TAG4 SELECT "INBOX"');
});
