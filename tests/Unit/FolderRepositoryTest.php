<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Enums\ImapSpecialUse;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\FolderData;
use DirectoryTree\ImapEngine\Mailbox;

test('it requests special-use attributes when supported', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SPECIAL-USE',
        'TAG2 OK CAPABILITY completed',
        '* LIST (\\Sent \\HasNoChildren) "/" "Outgoing"',
        'TAG3 OK LIST completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    $folders = $mailbox->folders()
        ->with(FolderData::SpecialUse)
        ->get();

    expect($folders->findBySpecialUse(ImapSpecialUse::Sent)?->path())->toBe('Outgoing');

    $stream->assertWritten('TAG3 LIST "" "*" RETURN (SPECIAL-USE)');
});

test('it does not infer special uses from folder names', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "Sent Items"',
        'TAG2 OK LIST completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    $folders = $mailbox->folders()->get();

    expect($folders->findBySpecialUse(ImapSpecialUse::Sent))->toBeNull();

    $stream->assertWritten('TAG2 LIST "" "*"');
    $stream->assertNotWritten('TAG2 LIST "" "*" RETURN (SPECIAL-USE)');
});

test('it resolves special-use attributes from an ordinary list response', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\Sent \\HasNoChildren) "/" "Outgoing"',
        'TAG2 OK LIST completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    $folders = $mailbox->folders()->get();

    expect($folders->findBySpecialUse(ImapSpecialUse::Sent)?->path())->toBe('Outgoing');

    $stream->assertWritten('TAG2 LIST "" "*"');
});

test('it resolves special-use attributes instead of matching folder names', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 SPECIAL-USE',
        'TAG2 OK CAPABILITY completed',
        '* LIST (\\HasNoChildren) "/" "Sent"',
        '* LIST (\\Sent \\HasNoChildren) "/" "Outgoing"',
        'TAG3 OK LIST completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    $folders = $mailbox->folders()
        ->with(FolderData::SpecialUse)
        ->get();

    expect($folders->findBySpecialUse(ImapSpecialUse::Sent)?->path())->toBe('Outgoing');
});

test('it returns null when a special-use folder cannot be resolved', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* LIST (\\HasNoChildren) "/" "INBOX"',
        'TAG2 OK LIST completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    $folders = $mailbox->folders()->get();

    expect($folders->findBySpecialUse(ImapSpecialUse::Archive))->toBeNull();
});

test('it throws when requested folder data is not supported', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1',
        'TAG2 OK CAPABILITY completed',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(new ImapConnection($stream));

    expect(fn () => $mailbox->folders()
        ->with(FolderData::SpecialUse)
        ->get()
    )->toThrow(ImapCapabilityException::class);
});
