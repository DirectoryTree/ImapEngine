<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;

test('config defaults', function () {
    $mailbox = Mailbox::make();

    expect($mailbox->config())->toBe([
        'port' => 993,
        'host' => '',
        'timeout' => 30,
        'debug' => false,
        'username' => '',
        'password' => '',
        'encryption' => 'ssl',
        'validate_cert' => true,
        'authentication' => 'plain',
        'proxy' => [
            'socket' => null,
            'username' => null,
            'password' => null,
            'request_fulluri' => false,
        ],
    ]);
});

test('config', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    expect($mailbox->config())->toBe([
        'port' => 993,
        'host' => '',
        'timeout' => 30,
        'debug' => false,
        'username' => 'foo',
        'password' => 'bar',
        'encryption' => 'ssl',
        'validate_cert' => true,
        'authentication' => 'plain',
        'proxy' => [
            'socket' => null,
            'username' => null,
            'password' => null,
            'request_fulluri' => false,
        ],
    ]);
});

test('config dot notated access', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'proxy' => ['username' => 'bar'],
    ]);

    expect($mailbox->config('username'))->toBe('foo');
    expect($mailbox->config('proxy.username'))->toBe('bar');
});

test('connect', function () {
    $mailbox = Mailbox::make();

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
    ]));

    expect($mailbox->connected())->toBeTrue();
});

test('connect throws exception with bad response', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 BAD Authentication failed',
    ]));
})->throws(ImapCommandException::class, 'IMAP command "TAG1 LOGIN [redacted] [redacted]" failed. Response: "TAG1 BAD Authentication failed"');

test('folders', function () {
    $mailbox = Mailbox::make();

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
    ]));

    $folders = $mailbox->folders()->get();

    expect($folders)->toHaveCount(1);
    expect($folders[0]->path())->toBe('INBOX');
    expect($folders[0]->flags())->toBe(['\\HasNoChildren']);
});

test('inbox', function () {
    $mailbox = Mailbox::make();

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
    ]));

    $folder = $mailbox->inbox();

    expect($folder)->toBeInstanceOf(Folder::class);

    expect($folder->path())->toBe('INBOX');
    expect($folder->flags())->toBe(['\\HasNoChildren']);
});
