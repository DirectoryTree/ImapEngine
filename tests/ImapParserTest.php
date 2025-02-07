<?php

use DirectoryTree\ImapEngine\Connection\Data\ListData;
use DirectoryTree\ImapEngine\Connection\FakeStream;
use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
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
