<?php

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
