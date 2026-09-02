<?php

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\FetchedMessageData;
use DirectoryTree\ImapEngine\Folder;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageData;

test('it creates a message from fetch response data', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* 5 FETCH (UID 42 FLAGS (\\Seen) RFC822.SIZE 1024 BODY[HEADER] "Subject: Test" BODY[TEXT] "Hello world")',
    ]);

    $response = (new ImapParser(new ImapTokenizer($stream)))->next();

    expect($response)->toBeInstanceOf(UntaggedResponse::class);

    $data = FetchedMessageData::fromResponse($response);
    $message = $data->toMessage(new Folder(new Mailbox, 'INBOX'));

    expect($data->uid())->toBe(42);
    expect($message->uid())->toBe(42);
    expect($message->flags())->toBe(['\\Seen']);
    expect($message->size())->toBe(1024);
    expect($message->head())->toBe('Subject: Test');
    expect($message->body())->toBe('Hello world');
});

test('it preserves standard and extension attributes without dedicated properties', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed('* 1 FETCH (UID 7 INTERNALDATE "02-Sep-2026 12:00:00 +0000" ENVELOPE (NIL "Subject" ((NIL NIL "steve" "example.com"))) EMAILID (M123) THREADID NIL X-CUSTOM ("Mixed Case" (NIL "NIL" 5)) RFC822.SIZE 0 MODSEQ (9223372036854775807))');

    $response = (new ImapParser(new ImapTokenizer($stream)))->next();
    $data = FetchedMessageData::fromResponse($response);

    expect($data->get('internaldate'))->toBe('02-Sep-2026 12:00:00 +0000');
    expect($data->get('ENVELOPE'))->toBe([null, 'Subject', [[null, null, 'steve', 'example.com']]]);
    expect($data->get('EMAILID'))->toBe(['M123']);
    expect($data->get('X-CUSTOM'))->toBe(['Mixed Case', [null, 'NIL', '5']]);
    expect($data->has('THREADID'))->toBeTrue();
    expect($data->get('THREADID', 'missing'))->toBeNull();
    expect($data->get('UNKNOWN', 'missing'))->toBe('missing');
    expect($data->size())->toBe(0);
    expect($data->modSequence())->toBe(9223372036854775807);
});

test('it preserves multiple body sections and partial offsets', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed('* 1 FETCH (UID 7 BODY[1.2] "complete" BODY[1.2]<0> "first" BODY[1.2]<5> "second" BODY[HEADER.FIELDS (SUBJECT FROM)] "headers" BODY[] "raw message" BODY ("TEXT" "PLAIN") BINARY.SIZE[1.2] 100)');

    $response = (new ImapParser(new ImapTokenizer($stream)))->next();
    $data = FetchedMessageData::fromResponse($response);

    expect($data->get('body[1.2]'))->toBe('complete');
    expect($data->get('BODY[1.2]<0>'))->toBe('first');
    expect($data->get('BODY[1.2]<5>'))->toBe('second');
    expect($data->get('BODY[HEADER.FIELDS (SUBJECT FROM)]'))->toBe('headers');
    expect($data->get('BODY[]'))->toBe('raw message');
    expect($data->get('BODY'))->toBe(['TEXT', 'PLAIN']);
    expect($data->get('BINARY.SIZE[1.2]'))->toBe('100');
});

test('it preserves binary literal content', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* 1 FETCH (UID 7 BINARY[1] ~{3}',
        "a\0b)",
    ]);

    $response = (new ImapParser(new ImapTokenizer($stream)))->next();
    $data = FetchedMessageData::fromResponse($response);

    expect($data->get('BINARY[1]'))->toBe("a\0b");
});

test('it distinguishes omitted attributes from empty or nil attributes', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* 1 FETCH (UID 7 MODSEQ (42))',
        '* 1 FETCH (UID 7 FLAGS () BODY[TEXT] "" THREADID NIL)',
    ]);

    $parser = new ImapParser(new ImapTokenizer($stream));
    $omitted = FetchedMessageData::fromResponse($parser->next());
    $empty = FetchedMessageData::fromResponse($parser->next());

    expect($omitted->has('FLAGS'))->toBeFalse();
    expect($omitted->flags())->toBe([]);
    expect($omitted->has('BODY[TEXT]'))->toBeFalse();
    expect($empty->has('FLAGS'))->toBeTrue();
    expect($empty->flags())->toBe([]);
    expect($empty->has('BODY[TEXT]'))->toBeTrue();
    expect($empty->body())->toBe('');
    expect($empty->has('THREADID'))->toBeTrue();
    expect($empty->get('THREADID'))->toBeNull();
});

test('merging partial updates preserves omitted attributes without mutating the original', function () {
    $original = new FetchedMessageData([
        'UID' => 7,
        'FLAGS' => ['\\Seen'],
        'BODY[TEXT]' => 'Existing content',
        'THREADID' => ['T123'],
    ]);
    $changes = new FetchedMessageData(['FLAGS' => [], 'MODSEQ' => [43], 'THREADID' => null]);

    $merged = $original->merge($changes)->merge(['emailid' => ['M123']]);

    expect($merged->uid())->toBe(7);
    expect($merged->flags())->toBe([]);
    expect($merged->body())->toBe('Existing content');
    expect($merged->modSequence())->toBe(43);
    expect($merged->has('THREADID'))->toBeTrue();
    expect($merged->get('THREADID'))->toBeNull();
    expect($merged->get('EMAILID'))->toBe(['M123']);
    expect($original->flags())->toBe(['\\Seen']);
    expect($original->has('MODSEQ'))->toBeFalse();
});

test('message conversion and serialization retain all fetched attributes', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed('* 1 FETCH (UID 7 FLAGS () BODY[1.2] "content" EMAILID (M123) THREADID NIL BODYSTRUCTURE ("text" "plain" NIL NIL NIL "7bit" 7 1))');

    $response = (new ImapParser(new ImapTokenizer($stream)))->next();
    $data = FetchedMessageData::fromResponse($response);
    $message = $data->toMessage(new Folder(new Mailbox, 'INBOX'));
    $restored = unserialize(serialize($message));

    expect($message->data())->toBe($data);
    expect($restored->data()->get('BODY[1.2]'))->toBe('content');
    expect($restored->data()->get('EMAILID'))->toBe(['M123']);
    expect($restored->data()->has('THREADID'))->toBeTrue();
    expect($restored->hasBodyStructure())->toBeTrue();
    expect($restored->bodyStructure())->not->toBeNull();
    expect($restored->toArray())->toBe($data->toArray());
    expect(json_decode(json_encode($restored), true))->toBe($data->toArray());
});

test('queried body sections reach messages and are reused only for peeking', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        'TAG2 OK SELECT completed',
        '* SEARCH 7',
        'TAG3 OK SEARCH completed',
        '* 1 FETCH (UID 7 BODY[1.2] "content")',
        'TAG4 OK FETCH completed',
        '* 1 FETCH (UID 7 BODY[1.2] "content" MODSEQ (43))',
        'TAG5 OK FETCH completed',
    ]);

    $mailbox = new Mailbox;
    $mailbox->connect(new ImapConnection($stream));
    $folder = new Folder($mailbox, 'INBOX');
    $message = $folder->messages()->only(MessageData::section('1.2')->peek())->get()->first();

    expect($message->data()->get('BODY[1.2]'))->toBe('content');
    expect($message->bodyPart('1.2'))->toBe('content');
    $stream->assertWritten('TAG4 UID FETCH 7 (BODY.PEEK[1.2])');
    $stream->assertNotWritten('TAG5');

    expect($message->bodyPart('1.2', peek: false))->toBe('content');
    expect($message->modSequence())->toBe(43);
    $stream->assertWritten('TAG5 UID FETCH 7 (BODY[1.2])');
});

test('partial body sections are not treated as complete cached parts', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 7 BODY[1.2] "complete content" EMAILID (M123))',
        'TAG2 OK FETCH completed',
    ]);

    $mailbox = new Mailbox;
    $mailbox->connect(new ImapConnection($stream));
    $message = new Message(new Folder($mailbox, 'INBOX'), new FetchedMessageData([
        'UID' => 7,
        'BODY[1.2]<0>' => 'partial',
        'FLAGS' => ['\\Seen'],
    ]));

    expect($message->bodyPart('1.2'))->toBe('complete content');
    expect($message->data()->get('BODY[1.2]<0>'))->toBe('partial');
    expect($message->data()->get('BODY[1.2]'))->toBe('complete content');
    expect($message->data()->get('EMAILID'))->toBe(['M123']);
    expect($message->flags())->toBe(['\\Seen']);
    $stream->assertWritten('TAG2 UID FETCH 7 (BODY.PEEK[1.2])');
});

test('empty fetched headers do not trigger repeated fetches', function () {
    $stream = new FakeStream;
    $stream->open();
    $stream->feed([
        '* OK Welcome to IMAP',
        'TAG1 OK Logged in',
        '* 1 FETCH (UID 7 BODY[HEADER] "")',
        'TAG2 OK FETCH completed',
    ]);

    $mailbox = new Mailbox;
    $mailbox->connect(new ImapConnection($stream));
    $message = new Message(new Folder($mailbox, 'INBOX'), new FetchedMessageData(['UID' => 7]));

    expect($message->data()->has('BODY[HEADER]'))->toBeFalse();
    expect($message->head(fetch: true))->toBe('');
    expect($message->data()->has('BODY[HEADER]'))->toBeTrue();
    expect($message->head(fetch: true))->toBe('');
    $stream->assertWritten('TAG2 UID FETCH 7 (BODY.PEEK[HEADER])');
    $stream->assertNotWritten('TAG3');
});
