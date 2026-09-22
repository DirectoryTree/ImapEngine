<?php

use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Selection\CondStore;
use DirectoryTree\ImapEngine\Selection\QuickResync;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeFolderRepository;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;

test('it can be created with basic properties', function () {
    $mailbox = FakeMailbox::make(
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
    $mailbox = FakeMailbox::make([
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
    $mailbox = FakeMailbox::make($config, [$folder]);
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
    $mailbox = FakeMailbox::make();

    expect($mailbox->connected())->toBeTrue();
});

test('it tracks enabled capabilities until reconnection', function () {
    $mailbox = FakeMailbox::make(capabilities: ['QRESYNC']);
    $capabilities = $mailbox->capabilities();

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeFalse();

    $mailbox->enable('qresync');

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeTrue();
    expect($capabilities->enabled('QRESYNC'))->toBeFalse();

    $mailbox->reconnect();

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeFalse();
    expect($mailbox->capabilities()->supports('QRESYNC'))->toBeTrue();
});

test('it rejects enabling unsupported capabilities', function () {
    $mailbox = FakeMailbox::make(capabilities: ['QRESYNC']);

    expect(fn () => $mailbox->enable('CONDSTORE'))->toThrow(
        ImapCapabilityException::class,
        'Unable to enable capability [CONDSTORE]. IMAP server does not support it.',
    );
});

test('it requires an exact advertised capability to enable', function () {
    $mailbox = FakeMailbox::make(capabilities: ['AUTH=XOAUTH2']);

    expect($mailbox->capabilities()->supports('AUTH'))->toBeTrue();
    expect(fn () => $mailbox->enable('AUTH'))->toThrow(
        ImapCapabilityException::class,
        'Unable to enable capability [AUTH]. IMAP server does not support it.',
    );
});

test('it returns folder repository', function () {
    $mailbox = FakeMailbox::make();

    expect($mailbox->folders())->toBeInstanceOf(FakeFolderRepository::class);
});

test('it can access inbox folder', function () {
    $inbox = new FakeFolder('inbox');
    $mailbox = FakeMailbox::make(folders: [$inbox]);

    expect($mailbox->inbox())->toBe($inbox);
});

test('it can select and check selected folders', function () {
    $folder = new FakeFolder('inbox');
    $mailbox = FakeMailbox::make(folders: [$folder]);

    expect($mailbox->selected($folder))->toBeFalse();

    $mailbox->select($folder);

    expect($mailbox->selected($folder))->toBeTrue();
});

test('it rejects selection options for unsupported capabilities', function () {
    $folder = new FakeFolder('inbox');
    $mailbox = FakeMailbox::make(folders: [$folder]);

    expect(fn () => $mailbox->select($folder, options: new CondStore))->toThrow(
        ImapCapabilityException::class,
        'Unable to select folder with [CONDSTORE]. IMAP server does not support it.',
    );

    expect($mailbox->selected($folder))->toBeFalse();
});

test('it enables selection options that require enablement', function () {
    $folder = new FakeFolder('inbox');
    $mailbox = FakeMailbox::make(folders: [$folder], capabilities: ['QRESYNC']);

    $mailbox->select($folder, options: new QuickResync(777, 42));

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeTrue();
    expect($mailbox->selected($folder))->toBeTrue();
});

test('it cannot enable selection options after selecting a folder', function () {
    $inbox = new FakeFolder('inbox');
    $archive = new FakeFolder('archive');
    $mailbox = FakeMailbox::make(folders: [$inbox, $archive], capabilities: ['QRESYNC']);

    $mailbox->select($inbox);

    expect(fn () => $mailbox->select($archive, options: new QuickResync(777, 42)))->toThrow(
        ImapCapabilityException::class,
        'Unable to enable capabilities while a folder is selected or examined. Reconnect before enabling them.',
    );

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeFalse();
    expect($mailbox->selected($inbox))->toBeTrue();
    expect($mailbox->selected($archive))->toBeFalse();
});

test('it cannot enable selection options after examining a folder', function () {
    $inbox = new FakeFolder('inbox');
    $archive = new FakeFolder('archive');
    $mailbox = FakeMailbox::make(folders: [$inbox, $archive], capabilities: ['QRESYNC']);

    $mailbox->examine($inbox);

    expect($mailbox->selected($inbox))->toBeFalse();
    expect(fn () => $mailbox->select($archive, options: new QuickResync(777, 42)))->toThrow(
        ImapCapabilityException::class,
        'Unable to enable capabilities while a folder is selected or examined. Reconnect before enabling them.',
    );

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeFalse();
    expect($mailbox->selected($archive))->toBeFalse();
});
