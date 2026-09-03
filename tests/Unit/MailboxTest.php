<?php

use DirectoryTree\ImapEngine\Connection\ConnectionInterface;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageQuery;

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
        'authentication' => 'login',
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
        'authentication' => 'login',
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

test('reconnect preserves the mailbox and its folders while updating only the password', function (?string $password, string $expectedPassword) {
    $mailbox = new class(['host' => 'imap.example.com', 'username' => 'foo', 'password' => 'old-password']) extends Mailbox
    {
        public array $connections = [];

        public function connect(?ConnectionInterface $connection = null): void
        {
            parent::connect($connection ?? array_shift($this->connections));
        }
    };

    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        'TAG2 OK Selected',
        'TAG3 OK Logged out',
    ]);
    $connection = new ImapConnection($stream);
    $mailbox->connect($connection);
    $config = $mailbox->config();
    $folder = new Folder($mailbox, 'INBOX');
    $folder->select();

    $reconnected = new FakeStream;
    $reconnected->feed([
        '* OK Ready',
        'TAG1 OK Logged in',
        'TAG2 OK Selected',
        'TAG3 OK Logged out',
    ]);
    $subsequent = new FakeStream;
    $subsequent->feed(['* OK Ready', 'TAG1 OK Logged in']);
    $mailbox->connections = [new ImapConnection($reconnected), new ImapConnection($subsequent)];

    $mailbox->reconnect(password: $password);

    expect($connection->connected())->toBeFalse();
    expect($mailbox->connected())->toBeTrue();
    expect($mailbox->config())->toBe([...$config, 'password' => $expectedPassword]);
    expect($mailbox->selected($folder))->toBeFalse();
    expect($folder->mailbox())->toBe($mailbox);
    expect($folder->messages())->toBeInstanceOf(MessageQuery::class);
    expect($mailbox->selected($folder))->toBeTrue();
    $stream->assertWritten("TAG3 LOGOUT\r\n");
    $reconnected->assertWritten("TAG1 LOGIN \"foo\" \"{$expectedPassword}\"\r\n");
    $reconnected->assertWritten("TAG2 SELECT \"INBOX\"\r\n");

    $mailbox->reconnect();

    expect($mailbox->config('password'))->toBe($expectedPassword);
    $subsequent->assertWritten("TAG1 LOGIN \"foo\" \"{$expectedPassword}\"\r\n");
})->with([
    'unchanged password' => [null, 'old-password'],
    'replacement password' => ['new-password', 'new-password'],
    'empty password' => ['', ''],
]);

test('reconnect retains a replacement token after authentication fails', function () {
    $mailbox = new class(['host' => 'imap.example.com', 'username' => 'foo', 'password' => 'old-token', 'authentication' => 'xoauth2']) extends Mailbox
    {
        public array $connections = [];

        public function connect(?ConnectionInterface $connection = null): void
        {
            parent::connect($connection ?? array_shift($this->connections));
        }
    };

    $mailbox->connect(ImapConnection::fake([
        '* OK Ready',
        '+',
        'TAG1 OK Authenticated',
        'TAG2 OK Logged out',
    ]));
    $config = $mailbox->config();

    $failed = new FakeStream;
    $failed->feed([
        '* OK Ready',
        '+',
        'TAG1 NO Authentication failed',
        'TAG2 OK Logged out',
    ]);
    $subsequent = new FakeStream;
    $subsequent->feed(['* OK Ready', '+', 'TAG1 OK Authenticated']);
    $mailbox->connections = [new ImapConnection($failed), new ImapConnection($subsequent)];

    expect(fn () => $mailbox->reconnect(password: 'new-token'))->toThrow(ImapCommandException::class);
    expect($mailbox->config())->toBe([...$config, 'password' => 'new-token']);
    $failed->assertWritten("TAG1 AUTHENTICATE XOAUTH2\r\n");
    $failed->assertWritten(base64_encode("user=foo\1auth=Bearer new-token\1\1")."\r\n");

    $mailbox->reconnect();

    expect($mailbox->connected())->toBeTrue();
    expect($mailbox->config('password'))->toBe('new-token');
    $subsequent->assertWritten("TAG1 AUTHENTICATE XOAUTH2\r\n");
    $subsequent->assertWritten(base64_encode("user=foo\1auth=Bearer new-token\1\1")."\r\n");
});

test('unsupported authentication mechanisms do not fall back to login', function (string $mechanism) {
    $stream = new FakeStream;
    $stream->feed('* OK Ready');
    $mailbox = Mailbox::make(['authentication' => $mechanism]);

    expect(fn () => $mailbox->connect(new ImapConnection($stream)))
        ->toThrow(InvalidArgumentException::class, 'Unsupported authentication mechanism.');
    $stream->assertNotWritten("TAG1 LOGIN \"\" \"\"\r\n");
})->with(['plain', 'oauth', 'unsupported']);

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
    expect($folders[0]->attributes())->toBe(['\\HasNoChildren']);
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
    expect($folder->attributes())->toBe(['\\HasNoChildren']);
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

    expect($mailbox->hasCapability('imap4rev1'))->toBeTrue();
    expect($mailbox->hasCapability('AUTH'))->toBeTrue();
    expect($mailbox->hasCapability('AUTH=PLAIN'))->toBeTrue();
    expect($mailbox->hasCapability('AUTH=LOGIN'))->toBeFalse();
    expect($mailbox->hasCapability('START'))->toBeFalse();
});
