<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;

test('disconnect clears capabilities and enabled extensions for the next connection', function () {
    $mailbox = Mailbox::make();
    $mailbox->connect(ImapConnection::fake([
        '* OK Ready',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 ENABLE QRESYNC',
        'TAG2 OK CAPABILITY completed',
        '* ENABLED QRESYNC',
        'TAG3 OK ENABLE completed',
        'TAG4 OK SELECT completed',
        'TAG5 OK LOGOUT completed',
    ]));

    $mailbox->enable('qresync');
    $folder = new Folder($mailbox, 'INBOX');
    $folder->select();

    expect($mailbox->capabilities()->enabled('qresync'))->toBeTrue();
    expect($mailbox->capabilities()->supports('QRESYNC'))->toBeTrue();

    $mailbox->disconnect();

    expect($mailbox->selected($folder))->toBeFalse();

    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 CONDSTORE',
        'TAG2 OK CAPABILITY completed',
    ]);
    $mailbox->connect(new ImapConnection($stream));

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeFalse();
    expect($mailbox->capabilities()->supports('QRESYNC'))->toBeFalse();
    expect($mailbox->capabilities()->supports('CONDSTORE'))->toBeTrue();
    $stream->assertWritten('TAG2 CAPABILITY');
});

test('clones discover their own capabilities without changing the original mailbox', function () {
    $mailbox = Mailbox::make();
    $mailbox->connect(ImapConnection::fake([
        '* OK Ready',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 ENABLE QRESYNC',
        'TAG2 OK CAPABILITY completed',
        '* ENABLED QRESYNC',
        'TAG3 OK ENABLE completed',
    ]));
    $mailbox->enable('QRESYNC');

    $clone = clone $mailbox;

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeTrue();

    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 CONDSTORE',
        'TAG2 OK CAPABILITY completed',
    ]);
    $clone->connect(new ImapConnection($stream));

    expect($clone->capabilities()->enabled('QRESYNC'))->toBeFalse();
    expect($clone->capabilities()->supports('QRESYNC'))->toBeFalse();
    expect($clone->capabilities()->supports('CONDSTORE'))->toBeTrue();
    expect($mailbox->capabilities()->supports('QRESYNC'))->toBeTrue();
    $stream->assertWritten('TAG2 CAPABILITY');
});
