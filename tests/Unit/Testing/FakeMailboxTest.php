<?php

use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
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
    expect($mailbox->capabilities()->all())->toBe(['IMAP4REV1', 'STARTTLS']);
    expect($mailbox->capabilities()->supports('imap4rev1'))->toBeTrue();
    expect($mailbox->capabilities()->supports('START'))->toBeFalse();
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

test('it reconnects while updating only the password', function (?string $password, string $expectedPassword) {
    $folder = new FakeFolder('inbox');
    $config = ['host' => 'imap.example.com', 'username' => 'foo', 'password' => 'old-password'];
    $mailbox = new FakeMailbox($config, [$folder]);
    $mailbox->select($folder);

    $mailbox->reconnect(password: $password);

    expect($mailbox->config())->toBe([...$config, 'password' => $expectedPassword]);
    expect($mailbox->selected($folder))->toBeFalse();
    expect($folder->mailbox())->toBe($mailbox);
    expect($mailbox->inbox())->toBe($folder);

    $mailbox->reconnect();

    expect($mailbox->config('password'))->toBe($expectedPassword);
})->with([
    'unchanged password' => [null, 'old-password'],
    'replacement password' => ['new-password', 'new-password'],
    'empty password' => ['', ''],
]);

test('it is always connected', function () {
    $mailbox = new FakeMailbox;

    expect($mailbox->connected())->toBeTrue();
});

test('it tracks enabled capabilities until reconnection', function () {
    $mailbox = new FakeMailbox(capabilities: ['QRESYNC']);

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeFalse();

    $mailbox->enable('qresync');

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeTrue();

    $mailbox->reconnect();

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeFalse();
    expect($mailbox->capabilities()->supports('QRESYNC'))->toBeTrue();
});

test('it rejects enabling unsupported capabilities', function () {
    $mailbox = new FakeMailbox(capabilities: ['QRESYNC']);

    expect(fn () => $mailbox->enable('CONDSTORE'))->toThrow(
        ImapCapabilityException::class,
        'Unable to enable capability [CONDSTORE]. IMAP server does not support it.',
    );
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
