<?php

use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeFolderRepository;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;

test('it can be created with basic properties', function () {
    $mailbox = new FakeMailbox(
        ['host' => 'imap.example.com', 'username' => 'user1'],
        [new FakeFolder('inbox')],
        ['IMAP4rev1', 'STARTTLS']
    );

    expect($mailbox)->toBeInstanceOf(FakeMailbox::class);
    expect($mailbox->config('host'))->toBe('imap.example.com');
    expect($mailbox->config('username'))->toBe('user1');
    expect($mailbox->capabilities())->toBe(['IMAP4rev1', 'STARTTLS']);
});

test('it returns config values correctly', function () {
    $mailbox = new FakeMailbox([
        'host' => 'imap.example.com',
        'port' => 993,
        'encryption' => 'ssl',
    ]);

    expect($mailbox->config('host'))->toBe('imap.example.com');
    expect($mailbox->config('port'))->toBe(993);
    expect($mailbox->config('encryption'))->toBe('ssl');
    expect($mailbox->config('unknown', 'default'))->toBe('default');
    expect($mailbox->config())->toBe([
        'host' => 'imap.example.com',
        'port' => 993,
        'encryption' => 'ssl',
    ]);
});

test('it is always connected', function () {
    $mailbox = new FakeMailbox;

    expect($mailbox->connected())->toBeTrue();
});

test('it returns folder repository', function () {
    $mailbox = new FakeMailbox;

    expect($mailbox->folders())->toBeInstanceOf(FakeFolderRepository::class);
});

test('it can access inbox folder', function () {
    $inbox = new FakeFolder('inbox');
    $mailbox = new FakeMailbox(folders: [$inbox]);

    expect($mailbox->inbox())->toBe($inbox);
});

test('it can select and check selected folders', function () {
    $folder = new FakeFolder('inbox');
    $mailbox = new FakeMailbox(folders: [$folder]);

    expect($mailbox->selected($folder))->toBeFalse();

    $mailbox->select($folder);

    expect($mailbox->selected($folder))->toBeTrue();
});
