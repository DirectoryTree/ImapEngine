<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
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
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
    ]);

    $mailbox = Mailbox::make();

    $mailbox->connect(new ImapConnection($stream));

    expect($mailbox->connected())->toBeTrue();
});

test('connect throws exception with bad response', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 BAD Authentication failed',
    ]);

    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));
})->throws(ImapCommandException::class, 'IMAP command "TAG1 LOGIN [redacted] [redacted]" failed. Response: "TAG1 BAD Authentication failed"');
