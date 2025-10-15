<?php

use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Streams\FakeStream;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Literal;
use DirectoryTree\ImapEngine\Connection\Tokens\QuotedString;

test('parses an untagged response including the asterisk token', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed('* OK Dovecot ready.');

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response = $parser->next();

    expect($response)->toBeInstanceOf(UntaggedResponse::class);
    expect($response->tokens())->toHaveCount(4);
    expect((string) $response)->toEqual('* OK Dovecot ready.');
});

test('parses a list response', function () {
    $stream = new FakeStream;
    $stream->open();

    // A simple list response.
    $stream->feed('(A B C)');

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response = $parser->next();

    expect($response)->toBeInstanceOf(ListData::class);
    expect($response->tokens())->toHaveCount(3);
    expect((string) $response)->toEqual('(A B C)');
});

test('parses a quoted string', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed('"Hello, world!"');

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response = $parser->next();

    expect($response)->toBeInstanceOf(QuotedString::class);
    expect((string) $response)->toEqual('"Hello, world!"');
});

test('parses a literal block', function () {
    $stream = new FakeStream;
    $stream->open();

    // Literal block: {<size>}\r\n followed immediately by that many characters.
    $stream->feed("{5}\r\nHello");

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response = $parser->next();

    expect($response)->toBeInstanceOf(Literal::class);
    expect((string) $response)->toEqual("{5}\r\nHello");
});

test('parses a nested list response', function () {
    $stream = new FakeStream;
    $stream->open();

    // A nested list: (A (B C) D)
    $stream->feed("(A (B C) D)\r\n");

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response = $parser->next();

    expect($response)->toBeInstanceOf(ListData::class);
    expect($response->tokens())->toHaveCount(3);

    $nestedList = $response->tokens()[1];

    expect($nestedList)->toBeInstanceOf(ListData::class);
    expect($nestedList->tokens())->toHaveCount(2);
});

test('parses a several lines', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* 2 FETCH (UID 102)',

        'TAG1 OK FETCH completed',

        '* 3 FETCH (UID 101 RFC822.HEADER {28}',
        'Subject: Foo',
        'Subject: Foo',
        ')',

        '* 4 FETCH (UID 102 RFC822.HEADER {14}',
        'Subject: Bar',
        ')',

        'TAG2 OK FETCH completed',
    ]);

    $tokenizer = new ImapTokenizer($stream);

    $parser = new ImapParser($tokenizer);

    $response1 = $parser->next();
    $response2 = $parser->next();
    $response3 = $parser->next();
    $response4 = $parser->next();
    $response5 = $parser->next();

    expect($response1)->toBeInstanceOf(UntaggedResponse::class);
    expect($response1->tokens())->toHaveCount(4);
    expect((string) $response1)->toBe('* 2 FETCH (UID 102)');

    expect($response2)->toBeInstanceOf(TaggedResponse::class);
    expect($response2->tokens())->toHaveCount(4);
    expect((string) $response2)->toBe('TAG1 OK FETCH completed');
    expect($response2->tag())->toBeInstanceOf(Atom::class);
    expect((string) $response2->tag())->toBe('TAG1');

    expect($response3)->toBeInstanceOf(UntaggedResponse::class);
    expect($response3->tokens())->toHaveCount(4);
    expect((string) $response3)->toBe("* 3 FETCH (UID 101 RFC822.HEADER {28}\r\nSubject: Foo\r\nSubject: Foo\r\n)");

    expect($response4)->toBeInstanceOf(UntaggedResponse::class);
    expect($response4->tokens())->toHaveCount(4);
    expect((string) $response4)->toBe("* 4 FETCH (UID 102 RFC822.HEADER {14}\r\nSubject: Bar\r\n)");

    expect($response5)->toBeInstanceOf(TaggedResponse::class);
    expect($response5->tokens())->toHaveCount(4);
    expect((string) $response5)->toBe('TAG2 OK FETCH completed');
});

test('parses list response', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* LIST (\Marked \NoInferiors) "/" "inbox"',
        '* LIST () "/" "Fruit"',
        '* LIST () "/" "Fruit/Apple"',
        '* LIST () "/" "Fruit/Banana"',
    ]);

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response1 = $parser->next();
    $response2 = $parser->next();
    $response3 = $parser->next();
    $response4 = $parser->next();

    expect($response1)->toBeInstanceOf(UntaggedResponse::class);
    expect($response1->tokens())->toHaveCount(5);
    expect((string) $response1)->toBe('* LIST (\Marked \NoInferiors) "/" "inbox"');

    expect($response2)->toBeInstanceOf(UntaggedResponse::class);
    expect($response2->tokens())->toHaveCount(5);
    expect((string) $response2)->toBe('* LIST () "/" "Fruit"');

    expect($response3)->toBeInstanceOf(UntaggedResponse::class);
    expect($response3->tokens())->toHaveCount(5);
    expect((string) $response3)->toBe('* LIST () "/" "Fruit/Apple"');

    expect($response4)->toBeInstanceOf(UntaggedResponse::class);
    expect($response4->tokens())->toHaveCount(5);
    expect((string) $response4)->toBe('* LIST () "/" "Fruit/Banana"');
});

test('parses quota response', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* QUOTA "#user/testuser" (STORAGE 512 1024)',
        'TAG1 OK GETQUOTA completed',
    ]);

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response1 = $parser->next();
    $response2 = $parser->next();

    expect($response1)->toBeInstanceOf(UntaggedResponse::class);
    expect($response1->tokens())->toHaveCount(4);
    expect((string) $response1)->toBe('* QUOTA "#user/testuser" (STORAGE 512 1024)');

    expect($response2)->toBeInstanceOf(TaggedResponse::class);
    expect($response2->tokens())->toHaveCount(4);
    expect((string) $response2)->toBe('TAG1 OK GETQUOTA completed');
});

test('parses bodystructure', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed([
        '* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "quoted-printable" 11 1 NIL NIL NIL) ("text" "html" ("charset" "utf-8") NIL NIL "quoted-printable" 18 1 NIL NIL NIL) "alternative" ("boundary" "Aq14h3UL") NIL NIL) UID 1)',
    ]);

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response = $parser->next();

    expect($response)->toBeInstanceOf(UntaggedResponse::class);
    expect($response->tokens())->toHaveCount(4);
    expect((string) $response)->toBe('* 1 FETCH (BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "quoted-printable" 11 1 NIL NIL NIL) ("text" "html" ("charset" "utf-8") NIL NIL "quoted-printable" 18 1 NIL NIL NIL) "alternative" ("boundary" "Aq14h3UL") NIL NIL) UID 1)');
});

test('parses response tokens', function (array|string $feed, string $type, string $value) {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed($feed);

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);
    $response = $parser->next();

    expect($response)->toBeInstanceOf($type);
    expect((string) $response)->toBe($value);
})->with([
    ['()', ListData::class, '()'],
    ['(A B C)', ListData::class, '(A B C)'],
    [['{0}', ''], Literal::class, "{0}\r\n"],
    ['(A (B C) D)', ListData::class, '(A (B C) D)'],
    [['{5}', 'Hello'], Literal::class, "{5}\r\nHello"],
    ['((A) (B (C)))', ListData::class, '((A) (B (C)))'],
    ['"Hello, world!"', QuotedString::class, '"Hello, world!"'],
    [['{12}', 'Hello', 'Bye'], Literal::class, "{12}\r\nHello\r\nBye\r\n"],
    ['* OK Dovecot ready.', UntaggedResponse::class, '* OK Dovecot ready.'],
    ['* 2 FETCH (UID 102)', UntaggedResponse::class, '* 2 FETCH (UID 102)'],
    ['TAG1 OK FETCH completed', TaggedResponse::class, 'TAG1 OK FETCH completed'],
    [
        '* QUOTA "#user/testuser" (STORAGE 512 1024)',
        UntaggedResponse::class, '* QUOTA "#user/testuser" (STORAGE 512 1024)',
    ],
    ['* SEARCH 1 2 3', UntaggedResponse::class, '* SEARCH 1 2 3'],
    ['A007 NO [ALERT] System busy', TaggedResponse::class, 'A007 NO [ALERT] System busy'],
    [
        [
            '* 1 FETCH (BODY {14}',
            'Hello World!',
            ')',
        ],
        UntaggedResponse::class,
        "* 1 FETCH (BODY {14}\r\nHello World!\r\n)",
    ],
]);

test('parses fetch response with body text then header', function () {
    $stream = new FakeStream;
    $stream->open();

    // Simulating BODY[TEXT] before BODY[HEADER]
    $stream->feedRaw([
        "* 1 FETCH (UID 123 FLAGS (\\Seen) BODY[TEXT] {13}\r\n",
        "Hello World\r\n",
        " BODY[HEADER] {23}\r\n",
        "Subject: Test Message\r\n",
        ")\r\n",
    ]);

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response = $parser->next();

    expect($response)->toBeInstanceOf(UntaggedResponse::class);

    // Get the ListData at index 3 (the FETCH data)
    $data = $response->tokenAt(3);
    expect($data)->toBeInstanceOf(ListData::class);

    // Verify we can lookup UID
    $uid = $data->lookup('UID');
    expect($uid)->not->toBeNull();
    expect($uid->value)->toBe('123');

    // Verify we can lookup FLAGS
    $flags = $data->lookup('FLAGS');
    expect($flags)->not->toBeNull();

    // Verify we can lookup both BODY sections with correct content
    $text = $data->lookup('[TEXT]');
    expect($text)->not->toBeNull();
    expect($text->value)->toBe("Hello World\r\n");

    $header = $data->lookup('[HEADER]');
    expect($header)->not->toBeNull();
    expect($header->value)->toBe("Subject: Test Message\r\n");
});

test('parses fetch response with body header then text', function () {
    $stream = new FakeStream;
    $stream->open();

    // Simulating BODY[HEADER] before BODY[TEXT]
    $stream->feedRaw([
        "* 1 FETCH (UID 456 FLAGS (\\Seen) BODY[HEADER] {26}\r\n",
        "From: sender@example.com\r\n",
        " BODY[TEXT] {20}\r\n",
        "Message body here.\r\n",
        ")\r\n",
    ]);

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response = $parser->next();

    expect($response)->toBeInstanceOf(UntaggedResponse::class);

    // Get the ListData at index 3 (the FETCH data)
    $data = $response->tokenAt(3);
    expect($data)->toBeInstanceOf(ListData::class);

    // Verify we can lookup UID
    $uid = $data->lookup('UID');
    expect($uid)->not->toBeNull();
    expect($uid->value)->toBe('456');

    // Verify we can lookup FLAGS
    $flags = $data->lookup('FLAGS');
    expect($flags)->not->toBeNull();

    // Verify we can lookup both BODY sections with correct content
    $header = $data->lookup('[HEADER]');
    expect($header)->not->toBeNull();
    expect($header->value)->toBe("From: sender@example.com\r\n");

    $text = $data->lookup('[TEXT]');
    expect($text)->not->toBeNull();
    expect($text->value)->toBe("Message body here.\r\n");
});

test('parses fetch response with all metadata and body parts', function () {
    $stream = new FakeStream;
    $stream->open();

    // Full FETCH response with all common fields
    $stream->feedRaw([
        "* 1 FETCH (UID 789 RFC822.SIZE 1024 FLAGS (\\Seen \\Flagged) BODY[TEXT] {25}\r\n",
        "This is the email body.\r\n",
        " BODY[HEADER] {46}\r\n",
        "To: recipient@example.com\r\nSubject: Re: Test\r\n",
        ")\r\n",
    ]);

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $response = $parser->next();

    expect($response)->toBeInstanceOf(UntaggedResponse::class);

    $data = $response->tokenAt(3);
    expect($data)->toBeInstanceOf(ListData::class);

    // Verify all lookups work correctly with actual content
    expect($data->lookup('UID')?->value)->toBe('789');
    expect($data->lookup('RFC822.SIZE')?->value)->toBe('1024');
    expect($data->lookup('FLAGS'))->not->toBeNull();
    expect($data->lookup('[TEXT]')->value)->toBe("This is the email body.\r\n");
    expect($data->lookup('[HEADER]')->value)->toBe("To: recipient@example.com\r\nSubject: Re: Test\r\n");
});
