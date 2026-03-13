<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Exceptions\ImapCapabilityException;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;

test('it moves message using MOVE when capable and returns the new UID', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 STARTTLS MOVE AUTH=PLAIN',
        'TAG2 OK CAPABILITY completed',
        'TAG3 OK [COPYUID 1234567890 1 42] MOVE completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'header', 'body');

    $newUid = $message->move('INBOX.Sent');

    expect($newUid)->toBe(42);
});

test('it copies and then deletes message using UIDPLUS when incapable of MOVE and returns the new UID', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 STARTTLS UIDPLUS AUTH=PLAIN',
        'TAG2 OK CAPABILITY completed',
        'TAG3 OK [COPYUID 1234567890 1 123] COPY completed',
        'TAG4 OK STORE completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'header', 'body');

    $newUid = $message->move('INBOX.Sent');

    expect($newUid)->toBe(123);
});

test('it throws exception when server does not support MOVE or UIDPLUS capabilities', function () {
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

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'header', 'body');

    $message->move('INBOX.Sent');
})->throws(ImapCapabilityException::class);

test('it can mark and unmark a message as flagged', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* CAPABILITY IMAP4rev1 STARTTLS AUTH=PLAIN',
        'TAG2 OK CAPABILITY completed',
        'TAG3 OK STORE completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'header', 'body');

    expect($message->isFlagged())->toBeFalse();
    expect($message->flags())->not->toContain('\\Flagged');

    $message->markFlagged();

    expect($message->isFlagged())->toBeTrue();
    expect($message->flags())->toContain('\\Flagged');
    expect($message->hasFlag(ImapFlag::Flagged))->toBeTrue();

    $message->unmarkFlagged();

    expect($message->isFlagged())->toBeFalse();
    expect($message->flags())->not->toContain('\\Flagged');
    expect($message->hasFlag(ImapFlag::Flagged))->toBeFalse();
});

test('it can determine if two messages are the same', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
    ]));

    $folder1 = new Folder($mailbox, 'INBOX', [], '/');
    $folder2 = new Folder($mailbox, 'INBOX.Sent', [], '/');

    // Create messages with different properties
    $message1 = new Message($folder1, 1, [], 'header1', 'body1');
    $message2 = new Message($folder1, 1, [], 'header1', 'body1'); // Same as message1
    $message3 = new Message($folder1, 2, [], 'header1', 'body1'); // Different UID
    $message4 = new Message($folder2, 1, [], 'header1', 'body1'); // Different folder
    $message5 = new Message($folder1, 1, [], 'header2', 'body1'); // Different header
    $message6 = new Message($folder1, 1, [], 'header1', 'body2'); // Different body

    // Same message
    expect($message1->is($message2))->toBeTrue();

    // Different header
    expect($message1->is($message5))->toBeTrue();

    // Different body
    expect($message1->is($message6))->toBeTrue();

    // Different UID
    expect($message1->is($message3))->toBeFalse();

    // Different folder
    expect($message1->is($message4))->toBeFalse();
});

test('it serializes and unserializes the message correctly', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $originalMessage = new Message(
        $folder,
        123,
        ['\\Seen', '\\Flagged'],
        'From: test@example.com',
        'This is the message body content',
        1024
    );

    $serialized = serialize($originalMessage);
    $unserializedMessage = unserialize($serialized);

    expect($unserializedMessage->uid())->toBe(123);
    expect($unserializedMessage->flags())->toBe(['\\Seen', '\\Flagged']);
    expect($unserializedMessage->head())->toBe('From: test@example.com');
    expect($unserializedMessage->body())->toBe('This is the message body content');
    expect($unserializedMessage->size())->toBe(1024);
});

test('it lazy loads text content from body structure when body is not loaded', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 1 BODY[1] {12}',
        'Hello World!',
        ')',
        'TAG2 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $bodyStructureData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 12 1 NIL NIL NIL) UID 1)'
    );

    $message = new Message($folder, 1, [], 'From: test@example.com', '', null, $bodyStructureData);

    expect($message->hasBody())->toBeFalse();
    expect($message->hasBodyStructure())->toBeTrue();
    expect($message->text(lazy: true))->toBe('Hello World!');
});

test('it lazy loads html content from body structure when body is not loaded', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 1 BODY[1] {21}',
        '<p>Hello World!</p>',
        ')',
        'TAG2 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $bodyStructureData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE ("text" "html" ("charset" "utf-8") NIL NIL "7bit" 19 1 NIL NIL NIL) UID 1)'
    );

    $message = new Message($folder, 1, [], 'From: test@example.com', '', null, $bodyStructureData);

    expect($message->hasBody())->toBeFalse();
    expect($message->hasBodyStructure())->toBeTrue();
    expect($message->html(lazy: true))->toBe('<p>Hello World!</p>');
});

test('it decodes base64 encoded content when lazy loading', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $encodedContent = base64_encode('Hello World!');

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 1 BODY[1] {'.(strlen($encodedContent) + 2).'}',
        $encodedContent,
        ')',
        'TAG2 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $bodyStructureData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "base64" 16 1 NIL NIL NIL) UID 1)'
    );

    $message = new Message($folder, 1, [], 'From: test@example.com', '', null, $bodyStructureData);

    expect($message->text(lazy: true))->toBe('Hello World!');
});

test('it decodes quoted-printable encoded content when lazy loading', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $encodedContent = 'Hello=20World!';

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 1 BODY[1] {'.(strlen($encodedContent) + 2).'}',
        $encodedContent,
        ')',
        'TAG2 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $bodyStructureData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "quoted-printable" 14 1 NIL NIL NIL) UID 1)'
    );

    $message = new Message($folder, 1, [], 'From: test@example.com', '', null, $bodyStructureData);

    expect($message->text(lazy: true))->toBe('Hello World!');
});

test('it converts charset to utf-8 when lazy loading', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    // ISO-8859-1 encoded content with special character
    $originalContent = 'Café';
    $encodedContent = mb_convert_encoding($originalContent, 'ISO-8859-1', 'UTF-8');

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 1 BODY[1] {'.(strlen($encodedContent) + 2).'}',
        $encodedContent,
        ')',
        'TAG2 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $bodyStructureData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE ("text" "plain" ("charset" "iso-8859-1") NIL NIL "7bit" 5 1 NIL NIL NIL) UID 1)'
    );

    $message = new Message($folder, 1, [], 'From: test@example.com', '', null, $bodyStructureData);

    expect($message->text(lazy: true))->toBe($originalContent);
});

test('it uses parsed body when body is already loaded', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $head = <<<'HEAD'
From: test@example.com
To: recipient@example.com
Subject: Test
MIME-Version: 1.0
Content-Type: text/plain; charset="UTF-8"
HEAD;

    $body = 'Hello from parsed body!';

    $message = new Message($folder, 1, [], $head, $body);

    expect($message->hasBody())->toBeTrue();
    expect($message->text())->toBe('Hello from parsed body!');
});

test('it lazy loads text from multipart message body structure', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 1 BODY[1] {14}',
        'Hello World!',
        ')',
        'TAG2 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    // Multipart alternative with text/plain at part 1 and text/html at part 2
    $bodyStructureData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 12 1 NIL NIL NIL) ("text" "html" ("charset" "utf-8") NIL NIL "7bit" 24 1 NIL NIL NIL) "alternative" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $message = new Message($folder, 1, [], 'From: test@example.com', '', null, $bodyStructureData);

    expect($message->hasBody())->toBeFalse();
    expect($message->hasBodyStructure())->toBeTrue();
    expect($message->text(lazy: true))->toBe('Hello World!');
});

test('it lazy loads html from multipart message body structure', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 1 BODY[2] {21}',
        '<p>Hello World!</p>',
        ')',
        'TAG2 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    // Multipart alternative with text/plain at part 1 and text/html at part 2
    $bodyStructureData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 12 1 NIL NIL NIL) ("text" "html" ("charset" "utf-8") NIL NIL "7bit" 19 1 NIL NIL NIL) "alternative" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $message = new Message($folder, 1, [], 'From: test@example.com', '', null, $bodyStructureData);

    expect($message->html(lazy: true))->toBe('<p>Hello World!</p>');
});

test('it fetches body structure automatically when not preloaded', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    // This simulates a message fetched without withBodyStructure(), then accessing text()
    // The server will respond with: 1) body structure fetch, 2) body part fetch
    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        // Response for BODYSTRUCTURE fetch
        '* 1 FETCH (UID 1 BODYSTRUCTURE ("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 12 1 NIL NIL NIL))',
        'TAG2 OK FETCH completed',
        // Response for BODY[1] fetch
        '* 1 FETCH (UID 1 BODY[1] {14}',
        'Hello World!',
        ')',
        'TAG3 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    // Message created without body structure data - simulates fetching without withBodyStructure()
    $message = new Message($folder, 1, [], 'From: test@example.com', '');

    expect($message->hasBody())->toBeFalse();
    expect($message->hasBodyStructure())->toBeFalse();

    // This should automatically fetch body structure, then fetch and decode the text part
    expect($message->text(lazy: true))->toBe('Hello World!');
});

test('it fetches body structure automatically for html when not preloaded', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        // Response for BODYSTRUCTURE fetch
        '* 1 FETCH (UID 1 BODYSTRUCTURE ("text" "html" ("charset" "utf-8") NIL NIL "7bit" 19 1 NIL NIL NIL))',
        'TAG2 OK FETCH completed',
        // Response for BODY[1] fetch
        '* 1 FETCH (UID 1 BODY[1] {21}',
        '<p>Hello World!</p>',
        ')',
        'TAG3 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    $message = new Message($folder, 1, [], 'From: test@example.com', '');

    expect($message->hasBodyStructure())->toBeFalse();
    expect($message->html(lazy: true))->toBe('<p>Hello World!</p>');
});

test('it lazy loads attachments from body structure', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $encodedContent = base64_encode('Hello World!');

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 1 BODY[2] {'.(strlen($encodedContent) + 2).'}',
        $encodedContent,
        ')',
        'TAG2 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    // Multipart mixed with text/plain at part 1 and PDF attachment at part 2
    $bodyStructureData = parseBodyStructureResponse(
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "7bit" 100 5 NIL NIL NIL) ("application" "pdf" ("name" "document.pdf") NIL NIL "base64" 5000 NIL ("attachment" ("filename" "document.pdf")) NIL NIL) "mixed" ("boundary" "abc") NIL NIL) UID 1)'
    );

    $message = new Message($folder, 1, [], 'From: test@example.com', '', null, $bodyStructureData);

    expect($message->hasBody())->toBeFalse();
    expect($message->hasBodyStructure())->toBeTrue();

    $attachments = $message->attachments(lazy: true);

    expect($attachments)->toHaveCount(1);
    expect($attachments[0]->filename())->toBe('document.pdf');
    expect($attachments[0]->contentType())->toBe('application/pdf');

    // Content is fetched lazily when contents() is called
    expect($attachments[0]->contents())->toBe('Hello World!');
});

test('it lazy loads headers from server', function () {
    $mailbox = Mailbox::make([
        'username' => 'foo',
        'password' => 'bar',
    ]);

    $headers = "Subject: Test Subject\r\nFrom: sender@example.com";

    $mailbox->connect(ImapConnection::fake([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 1 BODY[HEADER] {'.strlen($headers).'}',
        $headers,
        ')',
        'TAG2 OK FETCH completed',
    ]));

    $folder = new Folder($mailbox, 'INBOX', [], '/');

    // Create a message with just the UID - no headers or body
    $message = new Message($folder, 1, [], '', '');

    expect($message->hasHead())->toBeFalse();
    expect($message->hasBody())->toBeFalse();

    // Without lazy, returns null because message is empty
    expect($message->header('Subject'))->toBeNull();

    // With lazy, fetches headers from server
    $header = $message->header('Subject', lazy: true);

    expect($header)->not->toBeNull();
    expect($header->getValue())->toBe('Test Subject');
    expect($message->hasHead())->toBeTrue();
});
