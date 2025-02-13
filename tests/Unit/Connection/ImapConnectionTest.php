<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Exceptions\CommandFailedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;
use DirectoryTree\ImapEngine\Exceptions\ImapParseException;
use DirectoryTree\ImapEngine\Support\Str;

test('connect success', function () {
    $stream = new FakeStream;

    $stream->feed('* OK Welcome to IMAP');

    $connection = new ImapConnection($stream);

    expect($connection->connected())->toBeFalse();

    $connection->connect('imap.example.com', 143);

    expect($connection->connected())->toBeTrue();
});

test('connect failure', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed('* BAD');

    $connection = new ImapConnection($stream);

    $connection->connect('imap.example.com', 143);
})->throws(ConnectionFailedException::class);

test('login success', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->login('foo', 'bar');

    $stream->assertWritten('TAG1 LOGIN "foo" "bar"');
});

test('login failure', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 BAD Authentication failed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->login('foo', 'bar');
})->throws(CommandFailedException::class, 'IMAP command "TAG1 LOGIN [redacted] [redacted]" failed. Response: "TAG1 BAD Authentication failed"');

test('logout success', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged out',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->logout();

    $stream->assertWritten('TAG1 LOGOUT');
});

test('logout failure', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 BAD Logout failed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->logout();

    $stream->assertWritten('TAG1 LOGOUT');
});

test('authenticate success', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Authenticated',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->authenticate('foo', 'bar');

    $credentials = Str::credentials('foo', 'bar');

    $stream->assertWritten("TAG1 AUTHENTICATE XOAUTH2 $credentials");
});

test('authenticate failure', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 BAD Authentication failed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->authenticate('foo', 'bar');
})->throws(CommandFailedException::class, 'IMAP command "TAG1 AUTHENTICATE [redacted] [redacted]" failed. Response: "TAG1 BAD Authentication failed"');

test('start tls success', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Begin TLS negotiation now',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->startTls();

    $stream->assertWritten('TAG1 STARTTLS');
});

test('start tls failure', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 BAD TLS negotiation failed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->startTls();
})->throws(CommandFailedException::class, 'IMAP command "TAG1 STARTTLS" failed. Response: "TAG1 BAD TLS negotiation failed"');

test('done', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->done();

    $stream->assertWritten('DONE');
});

test('disconnect', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed('* OK Welcome to IMAP');

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    expect($connection->connected())->toBeTrue();

    $connection->disconnect();

    expect($connection->connected())->toBeFalse();
});

test('select folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 EXISTS',
        'TAG1 OK SELECT completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->select('INBOX');

    $stream->assertWritten('TAG1 SELECT "INBOX"');

    expect($responses->count())->toBe(1);
});

test('examine folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 EXISTS',
        'TAG1 OK EXAMINE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->examine('INBOX');

    $stream->assertWritten('TAG1 EXAMINE "INBOX"');

    expect($responses->count())->toBe(1);
});

test('status folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* STATUS "INBOX" (MESSAGES 10 UNSEEN 2 RECENT 0 UIDNEXT 11 UIDVALIDITY 123)',
        'TAG1 OK STATUS completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->status('INBOX');

    $stream->assertWritten('TAG1 STATUS "INBOX" (MESSAGES UNSEEN RECENT UIDNEXT UIDVALIDITY)');

    expect($response->type()->is('STATUS'))->toBeTrue();
});

test('create folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* LIST (\\HasNoChildren) "." "NewFolder"',
        'TAG1 OK CREATE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->create('NewFolder');

    $stream->assertWritten('TAG1 CREATE "NewFolder"');

    expect($responses->count())->toBeGreaterThan(0);
});

test('delete folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK DELETE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->delete('OldFolder');

    $stream->assertWritten('TAG1 DELETE "OldFolder"');

    expect($response->successful())->toBeTrue();
});

test('rename folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK RENAME completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->rename('OldFolder', 'NewFolder');

    $stream->assertWritten('TAG1 RENAME "OldFolder" "NewFolder"');

    expect($response->successful())->toBeTrue();
});

test('subscribe folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK SUBSCRIBE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->subscribe('Inbox');

    $stream->assertWritten('TAG1 SUBSCRIBE "Inbox"');

    expect($response->successful())->toBeTrue();
});

test('unsubscribe folder', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK UNSUBSCRIBE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->unsubscribe('Inbox');
    $stream->assertWritten('TAG1 UNSUBSCRIBE "Inbox"');

    expect($response->successful())->toBeTrue();
});

test('list folders', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* LIST (\\Noselect) "." "Folder1"',
        'TAG1 OK LIST completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->list('', '*');

    $stream->assertWritten('TAG1 LIST "" "*"');

    expect($responses->count())->toBeGreaterThan(0);
});

test('append message', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK APPEND completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->append('INBOX', 'Test message', ['\\Seen']);

    $stream->assertWritten('TAG1 APPEND "INBOX" (\Seen) "Test message"');
});

test('copy messages', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK UID COPY completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->copy('Archive', 1, 3);

    $stream->assertWritten('TAG1 UID COPY 1:3 "Archive"');
});

test('move messages', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK UID MOVE completed',
    ]);
    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->move('Archive', [1, 2, 3]);

    $stream->assertWritten('TAG1 UID MOVE 1,2,3 "Archive"');
});

test('store flags', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK UID STORE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->store(['\\Seen'], 1, 3, '+FLAGS');

    $stream->assertWritten('TAG1 UID STORE 1:3 +FLAGS.SILENT (\\Seen)');

    expect($response)->toBeInstanceOf(\DirectoryTree\ImapEngine\Collections\ResponseCollection::class);
});

test('uid fetch', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 123)',
        'TAG1 OK UID FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->uid(1);

    $stream->assertWritten('TAG1 FETCH 1 (UID)');

    expect((string) $responses->first())->toBe('* 1 FETCH (UID 123)');
});

test('text fetch with peek', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 1 BODY[TEXT] {14}',
        'Hello World!',
        ')',
        'TAG1 OK UID FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->bodyText(1);

    $stream->assertWritten('TAG1 UID FETCH 1 (BODY.PEEK[TEXT])');

    expect((string) $responses->first())->toBe("* 1 FETCH (UID 1 BODY [TEXT] {14}\r\nHello World!\r\n)");
});

test('header fetch with peek', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 1 BODY[HEADER] {14}',
        'Hello World!',
        ')',
        'TAG1 OK UID FETCH completed',
    ]);
    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->bodyHeader(1);

    $stream->assertWritten('TAG1 UID FETCH 1 (BODY.PEEK[HEADER])');

    expect((string) $responses->first())->toBe("* 1 FETCH (UID 1 BODY [HEADER] {14}\r\nHello World!\r\n)");
});

test('flags fetch', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 1 FLAGS (\\Seen))',
        'TAG1 OK UID FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->flags(1);

    $stream->assertWritten('TAG1 UID FETCH 1 (FLAGS)');

    expect((string) $responses->first())->toBe('* 1 FETCH (UID 1 FLAGS (\\Seen))');
});

test('sizes fetch', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 1 RFC822.SIZE 1024)',
        'TAG1 OK UID FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->sizes(1);

    $stream->assertWritten('TAG1 UID FETCH 1 (RFC822.SIZE)');

    expect((string) $responses->first())->toBe('* 1 FETCH (UID 1 RFC822.SIZE 1024)');
});

test('search', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* SEARCH 1 2 3',
        'TAG1 OK UID SEARCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->search(['ALL']);
    $stream->assertWritten('TAG1 UID SEARCH ALL');

    expect($response->type()->is('SEARCH'))->toBeTrue();
});

test('capability', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* CAPABILITY IMAP4rev1 STARTTLS AUTH=PLAIN',
        'TAG1 OK CAPABILITY completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->capability();

    $stream->assertWritten('TAG1 CAPABILITY');

    expect($response->type()->is('CAPABILITY'))->toBeTrue();
});

test('id with no parameters', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* ID ("name" "Acme IMAP Server" "version" "2.0" "support_id" "true")',
        'TAG1 OK ID completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->id();

    $stream->assertWritten('TAG1 ID NIL');

    expect($response->type()->is('ID'))->toBeTrue();
});

test('id with parameters', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* ID ("name" "Acme IMAP Server" "version" "2.0" "support_id" "true")',
        'TAG1 OK ID completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->id([
        'name' => 'Acme IMAP Server',
        'version' => '2.0',
        'support_id' => 'true',
    ]);

    $stream->assertWritten('TAG1 ID ("Acme IMAP Server" "2.0" "true")');

    expect($response->type()->is('ID'))->toBeTrue();
});

test('expunge', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 EXPUNGE',
        'TAG1 OK EXPUNGE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->expunge();

    $stream->assertWritten('TAG1 EXPUNGE');

    expect($responses->count())->toBeGreaterThan(0);
});

test('noop', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK NOOP completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $response = $connection->noop();

    $stream->assertWritten('TAG1 NOOP');

    expect($response->successful())->toBeTrue();
});

test('idle', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '+ idling',
        'TAG1 OK IDLE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    expect(function () use ($connection) {
        iterator_to_array($connection->idle(30));
    })->toThrow(ImapParseException::class);

    $stream->assertWritten('TAG1 IDLE');
});

test('fetch', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 123 FLAGS (\\Seen))',
        'TAG1 OK UID FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->fetch('FLAGS', 1);

    $stream->assertWritten('TAG1 UID FETCH 1 (FLAGS)');

    expect((string) $responses->first())->toBe("* 1 FETCH (UID 123 FLAGS (\Seen))");
});
