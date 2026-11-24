<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
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
        'tls' => [],
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
        'tls' => [],
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

test('serialization', function () {
    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $serialized = serialize($mailbox);

    expect(unserialize($serialized))
        ->toBeInstanceOf(Mailbox::class)
        ->config()->toBe($mailbox->config());
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

test('connect presents a client certificate alongside password authentication', function (string $encryption) {
    $stream = new class extends FakeStream
    {
        public function options(): array
        {
            return $this->connection['options'];
        }
    };

    $stream->feed(['* OK Welcome to IMAP', 'TAG1 OK Completed', 'TAG2 OK Completed']);

    $tls = [
        'local_cert' => '/path/to/client.pem',
        'local_pk' => '/path/to/client.key',
        'passphrase' => 'secret',
    ];

    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
        'encryption' => $encryption,
        'tls' => $tls,
    ]);

    $mailbox->connect(new ImapConnection($stream));

    $stream->assertWritten('LOGIN');
    expect($stream->options()['ssl'])->toBe([
        'verify_peer' => true,
        'verify_peer_name' => true,
        ...$tls,
    ]);
})->with(['ssl', 'tls', 'starttls']);

test('connect authenticates with a client certificate over STARTTLS', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Begin TLS negotiation',
        '* CAPABILITY IMAP4rev1 AUTH=EXTERNAL',
        'TAG2 OK CAPABILITY completed',
        '+',
        'TAG3 OK Authenticated',
    ]);

    $mailbox = Mailbox::make([
        'encryption' => 'starttls',
        'authentication' => 'external',
        'username' => 'foo@example.com',
        'tls' => ['local_cert' => '/path/to/client.pem'],
    ]);

    $mailbox->connect(new ImapConnection($stream));

    expect($mailbox->connected())->toBeTrue();
    $stream->assertWritten('TAG1 STARTTLS');
    $stream->assertWritten('TAG2 CAPABILITY');
    $stream->assertWritten('TAG3 AUTHENTICATE EXTERNAL');
    $stream->assertWritten(base64_encode('foo@example.com'));
});

test('connect closes the connection when the server does not support external authentication', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Welcome to IMAP',
        '* CAPABILITY IMAP4rev1 AUTH=PLAIN',
        'TAG1 OK CAPABILITY completed',
    ]);

    $mailbox = Mailbox::make([
        'authentication' => 'external',
        'tls' => ['local_cert' => '/path/to/client.pem'],
    ]);

    expect(fn () => $mailbox->connect(new ImapConnection($stream)))
        ->toThrow(ImapCapabilityException::class);
    expect($mailbox->connected())->toBeFalse();
    $stream->assertNotWritten('AUTHENTICATE');
    $stream->assertNotWritten('LOGIN');
});

test('mailboxes identified by different client certificates have distinct fingerprints', function () {
    $first = Mailbox::make([
        'authentication' => 'external',
        'tls' => ['local_cert' => '/path/to/first.pem'],
    ]);

    $second = Mailbox::make([
        'authentication' => 'external',
        'tls' => ['local_cert' => '/path/to/second.pem'],
    ]);

    expect($first->fingerprint())->not->toBe($second->fingerprint());
});

test('connect does not authenticate when the tls handshake fails', function (bool|int $result, string $authentication) {
    $stream = new class($result) extends FakeStream
    {
        public function __construct(protected bool|int $result) {}

        public function setSocketSetCrypto(bool $enabled, ?int $method): bool|int
        {
            return $this->result;
        }
    };

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Begin TLS negotiation now',
        'TAG2 OK Authenticated',
    ]);

    $mailbox = Mailbox::make([
        'host' => 'imap.example.com',
        'encryption' => 'starttls',
        'authentication' => $authentication,
        'username' => 'foo',
        'password' => 'bar',
    ]);

    expect(fn () => $mailbox->connect(new ImapConnection($stream)))
        ->toThrow(ImapConnectionFailedException::class);

    expect($mailbox->connected())->toBeFalse();

    $stream->assertWritten('TAG1 STARTTLS');
    $stream->assertNotWritten('LOGIN');
    $stream->assertNotWritten('AUTHENTICATE');
})->with([false, 0])->with(['plain', 'oauth', 'external']);

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

test('capabilities', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 STARTTLS AUTH=PLAIN',
        'TAG2 OK CAPABILITY completed',
    ]));

    expect($mailbox->capabilities())->toBe([
        'IMAP4rev1',
        'STARTTLS',
        'AUTH=PLAIN',
    ]);
});
