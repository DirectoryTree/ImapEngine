<?php

use DirectoryTree\ImapEngine\Authentication\XOAuth2;
use DirectoryTree\ImapEngine\Authenticator;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Loggers\LoggerInterface;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Mailbox;

test('oauth authentication optionally includes an initial response and redacts credentials', function (bool $initialResponse) {
    $stream = new FakeStream;
    $stream->feed(array_filter([
        '* OK Ready',
        $initialResponse ? null : '+',
        'TAG1 OK Authenticated',
    ]));

    $logger = new class implements LoggerInterface
    {
        public array $sent = [];

        public function sent(string $message): void
        {
            $this->sent[] = $message;
        }

        public function received(string $message): void {}
    };

    $connection = new ImapConnection($stream, $logger);
    $connection->connect('imap.example.com');
    $response = $connection->authenticate(new XOAuth2('foo', 'secret'), initialResponse: $initialResponse);

    $credentials = base64_encode("user=foo\1auth=Bearer secret\1\1");

    if ($initialResponse) {
        $stream->assertWritten("TAG1 AUTHENTICATE XOAUTH2 $credentials\r\n");
    } else {
        $stream->assertWritten("TAG1 AUTHENTICATE XOAUTH2\r\n");
        $stream->assertWritten($credentials."\r\n");
    }

    expect($response->successful())->toBeTrue();
    expect($logger->sent)->toBe(array_fill(0, $initialResponse ? 1 : 2, '[redacted]'));
})->with([false, true]);

test('oauth acknowledges an error challenge with an empty continuation and consumes completion', function (bool $initialResponse) {
    $stream = new FakeStream;
    $stream->feed(array_filter([
        '* OK Ready',
        $initialResponse ? null : '+',
        '+ '.base64_encode('{"status":"401","schemes":"bearer"}'),
        'TAG1 NO Invalid credentials',
        'TAG2 OK NOOP completed',
    ]));

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    expect(fn () => $connection->authenticate(new XOAuth2('foo', 'secret'), initialResponse: $initialResponse))
        ->toThrow(ImapCommandException::class);

    $credentials = base64_encode("user=foo\1auth=Bearer secret\1\1");

    if ($initialResponse) {
        $stream->assertWritten("TAG1 AUTHENTICATE XOAUTH2 $credentials\r\n");
    } else {
        $stream->assertWritten("TAG1 AUTHENTICATE XOAUTH2\r\n");
        $stream->assertWritten($credentials."\r\n");
    }

    $stream->assertWritten("\r\n");
    $stream->assertNotWritten('=');
    expect($connection->noop()->successful())->toBeTrue();
})->with([false, true]);

test('authentication supports multiple decoded challenges for custom mechanisms', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '+ '.base64_encode('Username:'),
        '+ '.base64_encode('Password:'),
        'TAG1 OK Authenticated',
    ]);

    $authenticator = new class implements Authenticator
    {
        public array $challenges = [];

        public function mechanism(): string
        {
            return 'LOGIN';
        }

        public function initialResponse(): ?string
        {
            return null;
        }

        public function respond(string $challenge): string
        {
            $this->challenges[] = $challenge;

            return match ($challenge) {
                'Username:' => 'foo',
                'Password:' => 'secret',
            };
        }
    };

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $connection->authenticate($authenticator, initialResponse: true);

    $stream->assertWritten("TAG1 AUTHENTICATE LOGIN\r\n");
    $stream->assertWritten(base64_encode('foo')."\r\n");
    $stream->assertWritten(base64_encode('secret')."\r\n");
    expect($authenticator->challenges)->toBe(['Username:', 'Password:']);
});

test('authentication encodes an empty initial response as equals', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        'TAG1 OK Authenticated',
    ]);

    $authenticator = new class implements Authenticator
    {
        public function mechanism(): string
        {
            return 'EXTERNAL';
        }

        public function initialResponse(): string
        {
            return '';
        }

        public function respond(string $challenge): string
        {
            return '';
        }
    };

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');
    $connection->authenticate($authenticator, initialResponse: true);

    $stream->assertWritten("TAG1 AUTHENTICATE EXTERNAL =\r\n");
});

test('authentication can be cancelled by the authenticator', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '+ '.base64_encode('challenge'),
        'TAG1 BAD Authentication cancelled',
        'TAG2 OK NOOP completed',
    ]);

    $authenticator = new class implements Authenticator
    {
        public function mechanism(): string
        {
            return 'X-CUSTOM';
        }

        public function initialResponse(): ?string
        {
            return null;
        }

        public function respond(string $challenge): ?string
        {
            return null;
        }
    };

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    expect(fn () => $connection->authenticate($authenticator))->toThrow(ImapCommandException::class);

    $stream->assertWritten("*\r\n");
    expect($connection->noop()->successful())->toBeTrue();
});

test('authenticator exceptions disconnect an unfinished authentication exchange', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '+ '.base64_encode('challenge'),
    ]);

    $authenticator = new class implements Authenticator
    {
        public function mechanism(): string
        {
            return 'X-CUSTOM';
        }

        public function initialResponse(): ?string
        {
            return null;
        }

        public function respond(string $challenge): ?string
        {
            throw new RuntimeException('Unable to respond');
        }
    };

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    expect(fn () => $connection->authenticate($authenticator))->toThrow(RuntimeException::class, 'Unable to respond');
    expect($connection->connected())->toBeFalse();
});

test('mailbox xoauth2 configuration uses challenge based authentication', function () {
    $stream = new FakeStream;
    $stream->feed([
        '* OK Ready',
        '+',
        'TAG1 OK Authenticated',
    ]);

    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'secret',
        'authentication' => 'xoauth2',
    ]);
    $mailbox->connect(new ImapConnection($stream));

    $stream->assertWritten("TAG1 AUTHENTICATE XOAUTH2\r\n");
    $stream->assertWritten(base64_encode("user=foo\1auth=Bearer secret\1\1")."\r\n");
    expect($mailbox->connected())->toBeTrue();
});
