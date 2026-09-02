<?php

use DirectoryTree\ImapEngine\AppendResult;
use DirectoryTree\ImapEngine\Authentication\XOAuth2;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Enums\ImapIdentifier;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
use DirectoryTree\ImapEngine\Fetch\ChangedSince;
use DirectoryTree\ImapEngine\FetchModifier;
use DirectoryTree\ImapEngine\FetchResult;
use DirectoryTree\ImapEngine\StoreResult;
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
})->throws(ImapConnectionFailedException::class);

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
})->throws(ImapCommandException::class, 'IMAP command "TAG1 LOGIN [redacted] [redacted]" failed. Response: "TAG1 BAD Authentication failed"');

test('logout success', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* BYE Logging out',
        'TAG1 OK Logged out',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->logout();

    $stream->assertWritten('TAG1 LOGOUT');
    expect($connection->connected())->toBeFalse();
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

    expect(fn () => $connection->logout())->toThrow(ImapCommandException::class);

    $stream->assertWritten('TAG1 LOGOUT');
    expect($connection->connected())->toBeFalse();
});

test('authenticate success', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '+',
        'TAG1 OK Authenticated',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->authenticate(new XOAuth2('foo', 'bar'));

    $credentials = Str::credentials('foo', 'bar');

    $stream->assertWritten('TAG1 AUTHENTICATE XOAUTH2');
    $stream->assertWritten($credentials);
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

    $connection->authenticate(new XOAuth2('foo', 'bar'));
})->throws(ImapCommandException::class, 'IMAP command "TAG1 AUTHENTICATE [redacted]" failed. Response: "TAG1 BAD Authentication failed"');

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
})->throws(ImapCommandException::class, 'IMAP command "TAG1 STARTTLS" failed. Response: "TAG1 BAD TLS negotiation failed"');

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

    $stream->assertWritten('TAG1 STATUS "INBOX" (MESSAGES UNSEEN UIDNEXT UIDVALIDITY)');

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

test('list folders with return options', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* LIST (\\Sent) "/" "Sent"',
        'TAG1 OK LIST completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->list('', '*', return: ['SPECIAL-USE']);

    $stream->assertWritten('TAG1 LIST "" "*" RETURN (SPECIAL-USE)');

    expect($responses)->toHaveCount(1);
});

test('append message', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '+ Ready',
        'TAG1 OK [APPENDUID 1234567890 42] APPEND completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->append('INBOX', 'Test message', ['\\Seen']);

    $stream->assertWritten('TAG1 APPEND "INBOX" (\Seen) {12}');
    $stream->assertWritten('Test message');

    expect($result)->toBeInstanceOf(AppendResult::class);
    expect($result->uidValidity())->toBe(1234567890);
    expect($result->uid())->toBe(42);
});

test('append message with internal date', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '+ Ready',
        'TAG1 OK APPEND completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->append(
        'INBOX',
        'Test message',
        ['\\Seen'],
        new DateTimeImmutable('2026-09-01 12:34:56 -04:00'),
    );

    $stream->assertWritten('TAG1 APPEND "INBOX" (\Seen) "01-Sep-2026 12:34:56 -0400" {12}');
    $stream->assertWritten('Test message');

    expect($result->uidValidity())->toBeNull();
    expect($result->uid())->toBeNull();
});

test('append sends literal data after receiving a continuation response', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '+ Ready for literal data',
        'TAG1 OK APPEND completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $message = "Subject: probe\r\n\r\nbody";

    $connection->append('INBOX', $message);

    $stream->assertWritten('TAG1 APPEND "INBOX" {22}');
    $stream->assertWritten($message);
});

test('append does not send literal data when the server rejects the command', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 NO [TRYCREATE] No such mailbox',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $message = "Subject: probe\r\n\r\nbody";

    expect(fn () => $connection->append('Missing', $message))
        ->toThrow(ImapCommandException::class);

    $stream->assertWritten('TAG1 APPEND "Missing" {22}');
    $stream->assertNotWritten($message);
});

test('send does not wait before sending non-synchronizing literal data', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed('* OK Welcome to IMAP');

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->send('APPEND', [
        '"INBOX"',
        ['{4+}', 'test'],
    ]);

    $stream->assertWritten('TAG1 APPEND "INBOX" {4+}');
    $stream->assertWritten('test');
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

    $connection->copy('1:3', 'Archive');

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

    $connection->move([1, 2, 3], 'Archive');

    $stream->assertWritten('TAG1 UID MOVE 1:3 "Archive"');
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

    $response = $connection->store('1:3', ['\\Seen']);

    $stream->assertWritten('TAG1 UID STORE 1:3 +FLAGS.SILENT (\\Seen)');

    expect($response)->toBeInstanceOf(StoreResult::class);
    expect($response->successful())->toBeTrue();
    expect($response->messages())->toBe([]);
    expect($response->modified())->toBe([]);
});

test('uid fetch with uid', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 123)',
        'TAG1 OK UID FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->fetch(1, 'UID');

    $stream->assertWritten('TAG1 UID FETCH 1 (UID)');

    expect($responses)->toBeInstanceOf(FetchResult::class);
    expect($responses->messages()[0]->uid())->toBe(123);
});

test('uid fetch with message number', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 FETCH (UID 123)',
        'TAG1 OK UID FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->fetch(1, 'UID', identifier: ImapIdentifier::MessageNumber);

    $stream->assertWritten('TAG1 FETCH 1 (UID)');

    expect($responses)->toBeInstanceOf(FetchResult::class);
    expect($responses->messages()[0]->uid())->toBe(123);
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

    $responses = $connection->fetch(1, 'BODY.PEEK[TEXT]');

    $stream->assertWritten('TAG1 UID FETCH 1 (BODY.PEEK[TEXT])');

    expect($responses->messages()[0]->body())->toBe("Hello World!\r\n");
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

    $responses = $connection->fetch(1, 'BODY.PEEK[HEADER]');

    $stream->assertWritten('TAG1 UID FETCH 1 (BODY.PEEK[HEADER])');

    expect($responses->messages()[0]->head())->toBe("Hello World!\r\n");
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

    $responses = $connection->fetch(1, 'FLAGS');

    $stream->assertWritten('TAG1 UID FETCH 1 (FLAGS)');

    expect($responses->messages()[0]->flags())->toBe(['\\Seen']);
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

    $responses = $connection->fetch(1, 'RFC822.SIZE');

    $stream->assertWritten('TAG1 UID FETCH 1 (RFC822.SIZE)');

    expect($responses->messages()[0]->size())->toBe(1024);
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

    $stream->assertWritten('TAG1 ID ("name" "Acme IMAP Server" "version" "2.0" "support_id" "true")');

    expect($response->type()->is('ID'))->toBeTrue();
});

test('id escapes special characters to prevent command injection', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '+ Ready',
        '* ID NIL',
        'TAG1 OK ID completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $connection->id([
        'name' => 'Evil"Client',
        'version' => "1.0\r\nLOGOUT",
        'vendor' => 'Test\\Vendor',
    ]);

    $stream->assertWritten('TAG1 ID ("name" "Evil\\"Client" "version" {11}');
    $stream->assertWritten("1.0\r\nLOGOUT".' "vendor" "Test\\\\Vendor")');
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

test('expunge messages by uid', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* OK Welcome to IMAP',
        '* 1 EXPUNGE',
        'TAG1 OK UID EXPUNGE completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $responses = $connection->expunge([1, 2, 3]);

    $stream->assertWritten('TAG1 UID EXPUNGE 1:3');

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
    })->toThrow(ImapConnectionException::class);

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

    $responses = $connection->fetch(1, 'FLAGS');

    $stream->assertWritten('TAG1 UID FETCH 1 (FLAGS)');

    expect($responses)->toBeInstanceOf(FetchResult::class);
    expect($responses->messages()[0]->uid())->toBe(123);
    expect($responses->messages()[0]->flags())->toBe(['\\Seen']);
    expect($responses->vanished())->toBe([]);
    expect($responses->vanishedUids())->toBe([]);
    expect($responses->responses())->toHaveCount(2);
});

test('fetch supports changed since with uid ranges', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        '* 2 FETCH (UID 7 FLAGS (\\Seen) MODSEQ (43))',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->fetch('1:*', 'FLAGS', modifiers: new ChangedSince(42));

    $stream->assertWritten('TAG1 UID FETCH 1:* (FLAGS) (CHANGEDSINCE 42)');
    expect($result)->toBeInstanceOf(FetchResult::class);
    expect($result->messages()[0]->modSequence())->toBe(43);
    expect($result->vanishedUids())->toBe([]);
});

test('fetch supports changed since with message numbers and a zero checkpoint', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        '* 2 FETCH (FLAGS (\\Seen) MODSEQ (43))',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->fetch([1, 2], 'FLAGS', identifier: ImapIdentifier::MessageNumber, modifiers: new ChangedSince(0));

    $stream->assertWritten('TAG1 FETCH 1:2 (FLAGS) (CHANGEDSINCE 0)');
    expect($result->messages())->toHaveCount(1);
    expect($result->messages()[0]->flags())->toBe(['\\Seen']);
    expect($result->messages()[0]->modSequence())->toBe(43);
});

test('fetch preserves raw responses while filtering unsolicited message data', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        '* 4 EXISTS',
        '* 2 FETCH (FLAGS (\\Seen))',
        '* 3 FETCH (UID 7 FLAGS () MODSEQ (43))',
        '* VANISHED (EARLIER) 1:2',
        '* VANISHED 2,4',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->fetch([1, 2, 4, 7], 'FLAGS', modifiers: new ChangedSince(42, vanished: true));

    expect($result->messages())->toHaveCount(1);
    expect($result->messages()[0]->uid())->toBe(7);
    expect($result->vanished())->toHaveCount(2);
    expect($result->vanished()[0]->earlier())->toBeTrue();
    expect($result->vanished()[1]->earlier())->toBeFalse();
    expect($result->vanishedUids())->toBe([1, 2, 4]);
    expect($result->responses())->toHaveCount(6);
    expect((string) $result->responses()->untagged()->first())->toBe('* 4 EXISTS');
});

test('fetch can return vanished uids without fetched messages', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        '* VANISHED (EARLIER) 1:2',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $result = $connection->fetch([1, 2], 'FLAGS', modifiers: new ChangedSince(42, vanished: true));

    expect($result->messages())->toBe([]);
    expect($result->vanishedUids())->toBe([1, 2]);
});

test('fetch combines custom modifiers into one modifier list', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    $custom = new class implements FetchModifier
    {
        public function toImap(): string
        {
            return 'X-CUSTOM';
        }
    };

    $result = $connection->fetch([1, 2], 'FLAGS', ImapIdentifier::Uid, new ChangedSince(42), $custom);

    $stream->assertWritten('TAG1 UID FETCH 1:2 (FLAGS) (CHANGEDSINCE 42 X-CUSTOM)');
    expect($result->messages())->toBe([]);
    expect($result->vanishedUids())->toBe([]);
});

test('fetch throws when the server rejects a modifier', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 BAD Unsupported FETCH modifier',
    ]);

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com');

    expect(fn () => $connection->fetch(1, 'FLAGS', modifiers: new ChangedSince(42)))
        ->toThrow(ImapCommandException::class);
});
