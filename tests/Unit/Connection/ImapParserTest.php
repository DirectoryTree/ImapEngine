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
