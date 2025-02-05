<?php

use DirectoryTree\ImapEngine\Connection\FakeStream;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;

test('tokenizer returns an ATOM token', function () {
    $stream = new FakeStream;

    $stream->open();

    // Feed a simple ATOM with trailing whitespace/newline.
    $stream->feed("ATOM1\r\n");

    $tokenizer = new ImapTokenizer($stream);
    $token = $tokenizer->nextToken();

    expect($token)->toEqual([
        'type' => 'ATOM',
        'value' => 'ATOM1',
    ]);
});

test('tokenizer returns a QUOTED_STRING token', function () {
    $stream = new FakeStream;

    $stream->open();

    // Feed a quoted string with CRLF at the end.
    $stream->feed("\"Hello, world!\"\r\n");

    $tokenizer = new ImapTokenizer($stream);

    $token = $tokenizer->nextToken();

    expect($token)->toEqual([
        'type' => 'QUOTED_STRING',
        'value' => 'Hello, world!',
    ]);
});

test('tokenizer returns a LITERAL token', function () {
    $stream = new FakeStream;

    $stream->open();

    // Feed a literal block. Literal syntax is {<size>}\r\n followed by that many bytes.
    $stream->feed("{5}\r\nHello");

    $tokenizer = new ImapTokenizer($stream);
    $token = $tokenizer->nextToken();

    expect($token)->toEqual([
        'type' => 'LITERAL',
        'value' => 'Hello',
    ]);
});

test('tokenizer returns LIST_OPEN and LIST_CLOSE tokens', function () {
    $stream = new FakeStream;
    $stream->open();
    // Feed a response that contains list delimiters.
    // The stream contains two separate tokens: "(" and ")".
    $stream->feed("(\r\n)\r\n");

    $tokenizer = new ImapTokenizer($stream);

    $tokenOpen = $tokenizer->nextToken();
    expect($tokenOpen)->toEqual([
        'type' => 'LIST_OPEN',
        'value' => '(',
    ]);

    $tokenClose = $tokenizer->nextToken();
    expect($tokenClose)->toEqual([
        'type' => 'LIST_CLOSE',
        'value' => ')',
    ]);
});

test('tokenizer handles escaped characters in quoted strings', function () {
    $stream = new FakeStream;
    $stream->open();
    // Feed a quoted string with escaped quotes.
    $stream->feed("\"Hello \\\"World\\\"!\"\r\n");

    $tokenizer = new ImapTokenizer($stream);
    $token = $tokenizer->nextToken();

    expect($token)->toEqual([
        'type' => 'QUOTED_STRING',
        'value' => 'Hello "World"!',
    ]);
});

test('tokenizer skips leading whitespace', function () {
    $stream = new FakeStream;

    $stream->open();

    // Feed data with leading whitespace before an ATOM.
    $stream->feed("   ATOM2\r\n");

    $tokenizer = new ImapTokenizer($stream);
    $token = $tokenizer->nextToken();

    expect($token)->toEqual([
        'type' => 'ATOM',
        'value' => 'ATOM2',
    ]);
});
