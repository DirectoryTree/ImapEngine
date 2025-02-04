<?php

use DirectoryTree\ImapEngine\Connection\FakeStream;
use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Response;
use DirectoryTree\ImapEngine\Exceptions\AuthFailedException;
use DirectoryTree\ImapEngine\Exceptions\ConnectionFailedException;
use DirectoryTree\ImapEngine\Exceptions\ImapServerErrorException;
use DirectoryTree\ImapEngine\Exceptions\RuntimeException;

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

    $stream->feed('BAD');

    $connection = new ImapConnection($stream);

    $connection->connect('imap.example.com', 143);
})->throws(ConnectionFailedException::class);

test('imap cert validation', function () {
    $protocol = new ImapConnection;

    expect($protocol->getCertValidation())->toBe(true);

    $protocol->setCertValidation(false);

    expect($protocol->getCertValidation())->toBe(false);
});

test('imap encryption', function () {
    $protocol = new ImapConnection;

    expect($protocol->getEncryption())->toBeNull();

    $protocol->setEncryption('ssl');

    expect($protocol->getEncryption())->toBe('ssl');
});

test('next line reads line successfully', function () {
    $fixture = '* OK IMAP4rev1 Service Ready';

    $stream = new FakeStream;

    $stream->open();

    $stream->feed($fixture);

    $protocol = new ImapConnection($stream);

    $response = new Response;

    $line = $protocol->nextLine($response);

    expect($line)->toBe('* OK IMAP4rev1 Service Ready');
    expect($response->getResponse())->toHaveCount(1);
    expect(implode('', $response->getResponse()))->toBe($fixture);
});

test('next line multi line fixture', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* OK Dovecot ready.',
        '* CAPABILITY IMAP4rev1 UIDPLUS',
        '1 OK CAPABILITY completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = new Response;

    $line1 = $connection->nextLine($response);
    expect($line1)->toBe('* OK Dovecot ready.');

    $line2 = $connection->nextLine($response);
    expect($line2)->toBe('* CAPABILITY IMAP4rev1 UIDPLUS');

    $line3 = $connection->nextLine($response);
    expect($line3)->toBe('1 OK CAPABILITY completed');

    expect($response->getResponse())->toHaveCount(3);
});

test('done', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed(['TAG1 OK Completed']);

    $connection = new ImapConnection($stream);

    $connection->done();

    $stream->assertWritten('DONE');

});

test('done with failed response', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('BAD');

    $connection = new ImapConnection($stream);

    $connection->done();
})->throws(RuntimeException::class, 'Done failed');

test('idle', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('+ idling');

    $connection = new ImapConnection($stream);

    $connection->idle();

    $stream->assertWritten('TAG1 IDLE');
});

test('idle with multi line response', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* OK Still here',
        'TAG1 OK',
        '+ idling',
    ]);

    $connection = new ImapConnection($stream);

    $connection->idle();

    $stream->assertWritten('TAG1 IDLE');
});

test('idle with failed response', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('BAD');

    $connection = new ImapConnection($stream);

    $connection->idle();
})->throws(RuntimeException::class, 'Idle failed. Unexpected response: BAD');

test('noop', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK NOOP completed');

    $connection = new ImapConnection($stream);

    $response = $connection->noop();

    $stream->assertWritten('TAG1 NOOP');

    expect($response->getResponse())->toBe(['TAG1 OK NOOP completed']);
});

test('capability', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* CAPABILITY IMAP4rev1 UNSELECT IDLE NAMESPACE',
        'TAG1 OK CAPABILITY completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->capability();

    $stream->assertWritten('TAG1 CAPABILITY');

    expect($response->getResponse())->toBe([
        '* CAPABILITY IMAP4rev1 UNSELECT IDLE NAMESPACE',
        'TAG1 OK CAPABILITY completed',
    ]);

    expect($response->getValidatedData())->toBe([
        'CAPABILITY',
        'IMAP4rev1',
        'UNSELECT',
        'IDLE',
        'NAMESPACE',
    ]);
});

test('capability failure', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('BAD');

    $connection = new ImapConnection($stream);

    $connection->capability();
})->throws(RuntimeException::class);

test('connect', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('* OK IMAP4rev1 Service Ready');

    $connection = new ImapConnection($stream);

    $connection->connect('imap.example.com', 143);

    expect($connection->connected())->toBeTrue();
});

test('login success', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK LOGIN done');

    $connection = new ImapConnection($stream);

    $response = $connection->login('user', 'pass');

    $stream->assertWritten('TAG1 LOGIN "user" "pass"');

    expect($response->getResponse())->toBe(['TAG1 OK LOGIN done']);
});

test('login failure', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 NO [AUTHENTICATIONFAILED] Authentication failed.');

    $connection = new ImapConnection($stream);

    $connection->login('user', 'invalid');
})->throws(ImapServerErrorException::class);

test('authenticate success', function () {
    $stream = new FakeStream;

    $stream->open();

    // Simulate a typical multi-line XOAUTH2 handshake.
    $stream->feed([
        '+ ',
        'TAG1 OK Authentication succeeded',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->authenticate('user@example.com', 'fake_token');

    $stream->assertWritten('TAG1 AUTHENTICATE XOAUTH2');

    expect($response->getResponse())->toBe([
        '+ ',
        'TAG1 OK Authentication succeeded',
    ]);
});

test('authenticate failure', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('BAD');

    $connection = new ImapConnection($stream);

    $connection->authenticate('user@example.com', 'bad_token');
})->throws(AuthFailedException::class);

test('logout', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* BYE Logging out',
        'TAG1 OK LOGOUT completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->logout();

    $stream->assertWritten('TAG1 LOGOUT');

    expect($response->getResponse())->toBe([
        '* BYE Logging out',
        'TAG1 OK LOGOUT completed',
    ]);
});

test('logout when already closed', function () {
    $stream = new FakeStream;

    $stream->feed('* OK IMAP4rev1 Service Ready');

    $connection = new ImapConnection($stream);
    $connection->connect('imap.example.com', 143);

    $stream->close(); // Forcibly close.

    $response = $connection->logout();

    expect($response->getResponse())->toBeEmpty();
    expect($connection->connected())->toBeFalse();
});

test('select folder', function () {
    $stream = new FakeStream;

    $stream->open();

    // Provide a typical SELECT response.
    $stream->feed([
        '* 23 EXISTS',
        '* 2 RECENT',
        '* OK [UIDVALIDITY 3857529045] UIDs valid',
        '* OK [UIDNEXT 4392] Predicted next UID',
        'TAG1 OK [READ-WRITE] SELECT completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->selectFolder('INBOX');

    $stream->assertWritten('TAG1 SELECT "INBOX"');

    expect(implode("\n", $response->getResponse()))
        ->toContain('OK [READ-WRITE] SELECT completed');
    expect($response->data())->toBeArray();
    expect($response->data()['exists'] ?? null)->toBe(23);
});

test('examine folder', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* 23 EXISTS',
        '* 2 RECENT',
        'TAG1 OK [READ-ONLY] EXAMINE completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->examineFolder('INBOX');

    $stream->assertWritten('TAG1 EXAMINE "INBOX"');

    expect($response->data()['exists'] ?? null)->toBe(23);
    expect($response->data()['recent'] ?? null)->toBe(2);
});

test('folder status', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* STATUS "INBOX" (MESSAGES 42 UNSEEN 3 RECENT 2 UIDNEXT 66 UIDVALIDITY 1)',
        'TAG1 OK STATUS completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->folderStatus('INBOX');

    $stream->assertWritten('TAG1 STATUS "INBOX" (MESSAGES UNSEEN RECENT UIDNEXT UIDVALIDITY)');

    expect($response->data()['messages'] ?? null)->toBe(42);
    expect($response->data()['unseen'] ?? null)->toBe(3);
    expect($response->data()['recent'] ?? null)->toBe(2);
    expect($response->data()['uidnext'] ?? null)->toBe(66);
    expect($response->data()['uidvalidity'] ?? null)->toBe(1);
});

test('fetch single', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* 1 FETCH (UID 1 BODY[] {12}',
        'Hello world!',
        ')',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->fetch('BODY[]', [1]);

    $stream->assertWritten('TAG1 UID FETCH 1:1 (BODY[])');

    expect($response->data()[1])->toContain('Hello world!');
});

test('fetch range', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* 1 FETCH (UID 1 BODY[] {13}',
        'Message #1...',
        ')',
        '* 2 FETCH (UID 2 BODY[] {13}',
        'Message #2...',
        ')',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);

    // Fetch messages 1-2.
    $response = $connection->fetch('BODY[]', 1, 2);

    $stream->assertWritten('TAG1 UID FETCH 1:2 (BODY[])');

    $result = $response->data();

    expect($result)->toHaveCount(2);
    expect($result)->toHaveKey(1);
    expect($result)->toHaveKey(2);
});

test('content', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* 1 FETCH (UID 999 RFC822.TEXT {18}',
        'message content...',
        ')',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->content(999);

    $stream->assertWritten('TAG1 UID FETCH 999:999 (RFC822.TEXT)');

    expect($response->data()[999])->toContain('message content...');
});

test('headers', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* 1 FETCH (UID 777 RFC822.HEADER {13}',
        'Subject: Test',
        ')',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->headers(777);

    $stream->assertWritten('TAG1 UID FETCH 777:777 (RFC822.HEADER)');

    expect($response->data()[777])->toContain('Subject: Test');
});

test('flags', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* 1 FETCH (UID 3 FLAGS (\\Seen))',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->flags(3);

    $stream->assertWritten('TAG1 UID FETCH 3:3 (FLAGS)');

    expect($response->data()[3])->toBe(['\\Seen']);
});

test('sizes', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* 1 FETCH (UID 4 RFC822.SIZE 12345)',
        'TAG1 OK FETCH completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->sizes(4);

    $stream->assertWritten('TAG1 UID FETCH 4:4 (RFC822.SIZE)');

    expect($response->data()[4])->toEqual(12345);
});

test('folders', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* LIST (\\HasNoChildren) "." "INBOX"',
        '* LIST (\\HasChildren) "." "Archive"',
        'TAG1 OK LIST completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->folders('', '*');

    $stream->assertWritten('TAG1 LIST "" "*"');

    expect($response->data())->toHaveKey('INBOX');
    expect($response->data())->toHaveKey('Archive');
});

test('store add flag', function () {
    $stream = new FakeStream;

    $stream->open();

    // Non-silent store typically returns updated flags:
    $stream->feed([
        '* 5 FETCH (FLAGS (\\Seen \\Flagged))',
        'TAG1 OK STORE completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->store(['\\Flagged'], 5, null, '+', false);

    $stream->assertWritten('TAG1 UID STORE 5 +FLAGS (\\Flagged)');

    expect($response->data()[5])->toBe(['\\Seen', '\\Flagged']);
});

test('append message', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK APPEND completed');

    $connection = new ImapConnection($stream);

    $response = $connection->appendMessage('INBOX', 'Raw message data', ['\\Seen'], '12-Jun-2023 12:00:00 +0000');

    $stream->assertWritten('TAG1 APPEND "INBOX" (\\Seen) "12-Jun-2023 12:00:00 +0000" "Raw message data"');

    expect($response->getResponse())->toBe(['TAG1 OK APPEND completed']);
});

test('copy message', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK COPY completed');

    $connection = new ImapConnection($stream);

    $response = $connection->copyMessage('Archive', 7);

    $stream->assertWritten('TAG1 UID COPY 7 "Archive"');

    expect($response->getResponse())->toBe(['TAG1 OK COPY completed']);
});

test('copy many messages', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK COPY completed');

    $connection = new ImapConnection($stream);

    $response = $connection->copyManyMessages([1, 2, 3], 'Archive');

    $stream->assertWritten('TAG1 UID COPY 1,2,3 "Archive"');

    expect($response->getResponse())->toBe(['TAG1 OK COPY completed']);
});

test('move message', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK MOVE completed');

    $connection = new ImapConnection($stream);

    $response = $connection->moveMessage('Trash', 10);

    $stream->assertWritten('TAG1 UID MOVE 10 "Trash"');

    expect($response->getResponse())->toBe(['TAG1 OK MOVE completed']);
});

test('move many messages', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK MOVE completed');

    $connection = new ImapConnection($stream);

    $response = $connection->moveManyMessages([10, 11, 12], 'Trash');

    $stream->assertWritten('TAG1 UID MOVE 10,11,12 "Trash"');

    expect($response->getResponse())->toBe(['TAG1 OK MOVE completed']);
});

test('id command', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* ID ("name" "Dovecot")',
        'TAG1 OK ID completed',
    ]);

    $connection = new ImapConnection($stream);

    // Provide some client data.
    $response = $connection->id(['name', 'MyClient']);

    $stream->assertWritten('TAG1 ID ("name" "MyClient")');

    expect($response->getResponse())->toBe([
        '* ID ("name" "Dovecot")',
        'TAG1 OK ID completed',
    ]);
});

test('create folder', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK CREATE completed');

    $connection = new ImapConnection($stream);

    $response = $connection->createFolder('NewFolder');

    $stream->assertWritten('TAG1 CREATE "NewFolder"');

    expect($response->getResponse())->toBe(['TAG1 OK CREATE completed']);
});

test('rename folder', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK RENAME completed');

    $connection = new ImapConnection($stream);

    $response = $connection->renameFolder('OldFolder', 'RenamedFolder');

    $stream->assertWritten('TAG1 RENAME "OldFolder" "RenamedFolder"');

    expect($response->getResponse())->toBe(['TAG1 OK RENAME completed']);
});

test('delete folder', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK DELETE completed');

    $connection = new ImapConnection($stream);

    $response = $connection->deleteFolder('Trash');

    $stream->assertWritten('TAG1 DELETE "Trash"');

    expect($response->getResponse())->toBe(['TAG1 OK DELETE completed']);
});

test('subscribe folder', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK SUBSCRIBE completed');

    $connection = new ImapConnection($stream);

    $response = $connection->subscribeFolder('Newsletters');

    $stream->assertWritten('TAG1 SUBSCRIBE "Newsletters"');

    expect($response->getResponse())->toBe(['TAG1 OK SUBSCRIBE completed']);
});

test('unsubscribe folder', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('TAG1 OK UNSUBSCRIBE completed');

    $connection = new ImapConnection($stream);

    $response = $connection->unsubscribeFolder('Newsletters');

    $stream->assertWritten('TAG1 UNSUBSCRIBE "Newsletters"');

    expect($response->getResponse())->toBe(['TAG1 OK UNSUBSCRIBE completed']);
});

test('expunge', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* 1 EXPUNGE',
        '* 2 EXPUNGE',
        'TAG1 OK EXPUNGE completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->expunge();

    $stream->assertWritten('TAG1 EXPUNGE');

    expect($response->getResponse())->toBe([
        '* 1 EXPUNGE',
        '* 2 EXPUNGE',
        'TAG1 OK EXPUNGE completed',
    ]);
});

test('search', function () {
    $stream = new FakeStream;

    $stream->open();

    // Searching might return multiple IDs in a single line.
    $stream->feed([
        '* SEARCH 3 5 7 8',
        'TAG1 OK SEARCH completed',
    ]);

    $connection = new ImapConnection($stream);

    // For example: Searching "ALL", or "FROM", etc.
    $response = $connection->search(['ALL']);

    $stream->assertWritten('TAG1 UID SEARCH ALL');

    expect($response->data())->toEqual([3, 5, 7, 8]);
});

test('get quota', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* QUOTA "#user/testuser" (STORAGE 512 1024)',
        'TAG1 OK GETQUOTA completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->getQuota('testuser');

    $stream->assertWritten('TAG1 GETQUOTA "#user/testuser"');

    expect($response->getResponse())->toBe([
        '* QUOTA "#user/testuser" (STORAGE 512 1024)',
        'TAG1 OK GETQUOTA completed',
    ]);
});

test('get quota root', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed([
        '* QUOTAROOT INBOX "#user/testuser"',
        '* QUOTA "#user/testuser" (STORAGE 512 1024)',
        'TAG1 OK GETQUOTAROOT completed',
    ]);

    $connection = new ImapConnection($stream);

    $response = $connection->getQuotaRoot('INBOX');

    $stream->assertWritten('TAG1 GETQUOTAROOT INBOX');

    expect(implode("\n", $response->getResponse()))
        ->toContain('QUOTAROOT INBOX');
});

test('build set', function () {
    $connection = new ImapConnection;

    expect($connection->buildSet(5, 10))->toBe('5:10');
    expect($connection->buildSet(5, INF))->toBe('5:*');
    expect($connection->buildSet(5))->toBe(5);
});
