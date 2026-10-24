<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Selection\CondStore;
use DirectoryTree\ImapEngine\Selection\QuickResync;

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

    $capabilities = $mailbox->capabilities();

    $mailbox->enable('qresync');
    $folder = new Folder($mailbox, 'INBOX');
    $folder->select();

    expect($mailbox->capabilities()->enabled('qresync'))->toBeTrue();
    expect($capabilities->enabled('qresync'))->toBeFalse();
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

test('enable requires an exact advertised capability', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 ENABLE AUTH=XOAUTH2',
        'TAG2 OK CAPABILITY completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));

    expect($mailbox->capabilities()->supports('AUTH'))->toBeTrue();
    expect(fn () => $mailbox->enable('AUTH'))->toThrow(
        ImapCapabilityException::class,
        'Unable to enable capability [AUTH]. IMAP server does not support it.',
    );

    $stream->assertNotWritten('ENABLE AUTH');
});

test('failed selection clears the previous folder so capabilities can be enabled', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        'TAG2 OK SELECT completed',
        'TAG3 NO Mailbox unavailable',
        '* CAPABILITY IMAP4rev1 ENABLE QRESYNC',
        'TAG4 OK CAPABILITY completed',
        '* ENABLED QRESYNC',
        'TAG5 OK ENABLE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $inbox = new Folder($mailbox, 'INBOX');
    $missing = new Folder($mailbox, 'Missing');

    $inbox->select();

    expect(fn () => $missing->select())->toThrow(ImapCommandException::class);
    expect($mailbox->selected($inbox))->toBeFalse();

    $mailbox->enable('QRESYNC');

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeTrue();
    $stream->assertWritten('TAG5 ENABLE QRESYNC');
});

test('failed examination clears the previous folder so capabilities can be enabled', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        'TAG2 OK SELECT completed',
        'TAG3 NO Mailbox unavailable',
        '* CAPABILITY IMAP4rev1 ENABLE QRESYNC',
        'TAG4 OK CAPABILITY completed',
        '* ENABLED QRESYNC',
        'TAG5 OK ENABLE completed',
    ]);

    $mailbox = Mailbox::make();
    $mailbox->connect(new ImapConnection($stream));
    $inbox = new Folder($mailbox, 'INBOX');
    $missing = new Folder($mailbox, 'Missing');

    $inbox->select();

    expect(fn () => $missing->examine())->toThrow(ImapCommandException::class);
    expect($mailbox->selected($inbox))->toBeFalse();

    $mailbox->enable('QRESYNC');

    expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeTrue();
    $stream->assertWritten('TAG5 ENABLE QRESYNC');
});

test('examine forwards selection options and enables qresync before selection', function (bool $qresync) {
    $option = $qresync ? new QuickResync(1, 42) : new CondStore;
    $tag = $qresync ? 4 : 3;
    $connection = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* CAPABILITY IMAP4rev1 ENABLE CONDSTORE QRESYNC', 'TAG2 OK CAPABILITY completed',
        ...($qresync ? ['* ENABLED QRESYNC', 'TAG3 OK ENABLE completed'] : []),
        '* OK [HIGHESTMODSEQ 43]', "TAG{$tag} OK EXAMINE completed",
    ]);
    $mailbox = new Mailbox;
    $mailbox->connect($connection);
    $folder = new Folder($mailbox, 'INBOX');

    expect($folder->examine($option))->not->toBeEmpty();
    expect($mailbox->selected($folder))->toBeFalse();
    $connection->stream()->assertWritten('TAG'.$tag.' EXAMINE "INBOX" ('.$option->toImap().')');
    if ($qresync) {
        $connection->stream()->assertWritten('TAG3 ENABLE QRESYNC');
        expect($mailbox->capabilities()->enabled('QRESYNC'))->toBeTrue();
    }
    $connection->disconnect();
})->with([false, true]);

test('examine rejects unsupported options before sending the command', function () {
    $connection = ImapConnection::fake([
        '* OK Welcome', 'TAG1 OK LOGIN completed',
        '* CAPABILITY IMAP4rev1', 'TAG2 OK CAPABILITY completed',
    ]);
    $mailbox = new Mailbox;
    $mailbox->connect($connection);

    expect(fn () => $mailbox->examine(new Folder($mailbox, 'INBOX'), new CondStore))
        ->toThrow(ImapCapabilityException::class);
    $connection->stream()->assertNotWritten('EXAMINE');
    $connection->disconnect();
});
