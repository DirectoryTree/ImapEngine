<?php

use DirectoryTree\ImapEngine\Connection\FakeStream;
use DirectoryTree\ImapEngine\Connection\ImapParser;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;

test('parses an untagged response including the asterisk token', function () {
    $stream = new FakeStream;

    $stream->open();

    $stream->feed('* OK Dovecot ready.');

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    $result = $parser->parse();
    expect($result)->toEqual(['*', 'OK', 'Dovecot', 'ready.']);
});

test('parses a list response', function () {
    $stream = new FakeStream;

    $stream->open();

    // A simple list response.
    $stream->feed('(A B C)');

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    expect($parser->parse())->toEqual(['A', 'B', 'C']);
});

test('parses a quoted string', function () {
    $stream = new FakeStream;

    $stream->open();

    // Feed a quoted string.
    $stream->feed('"Hello, world!"');

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    expect($parser->parse())->toEqual('Hello, world!');
});

test('parses a literal block', function () {
    $stream = new FakeStream;

    $stream->open();
    // Literal block: {<size>}\r\n followed immediately by that many characters.
    $stream->feed("{5}\r\nHello");

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    expect($parser->parse())->toEqual('Hello');
});

test('parses a nested list response', function () {
    $stream = new FakeStream;

    $stream->open();

    // A nested list: (A (B C) D)
    $stream->feed("(A (B C) D)\r\n");

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    expect($parser->parse())->toEqual(['A', ['B', 'C'], 'D']);
});

test('parses a tagged response', function () {
    $stream = new FakeStream;

    $stream->open();

    // Provide only one UID
    // Simulate fetching UIDs for messages 1 and 2
    $stream->feed([
        '* 1 FETCH (UID 101)',
        '* 2 FETCH (UID 102)',

        'TAG1 OK FETCH completed',

        '* 3 FETCH (UID 101 RFC822.HEADER {12}',
        'Subject: Foo',
        ')',
        '* 4 FETCH (UID 102 RFC822.HEADER {12}',
        'Subject: Bar',
        ')',

        'TAG2 OK FETCH completed',
    ]);

    $tokenizer = new ImapTokenizer($stream);
    $parser = new ImapParser($tokenizer);

    expect($parser->parse())->toEqual([
        '*', '1', 'FETCH', ['UID', '101'],
        '*', '2', 'FETCH', ['UID', '102'],
        'TAG1', 'OK', 'FETCH', 'completed',
        '*', '3', 'FETCH', ['UID', '101', 'RFC822.HEADER', 'Subject: Foo'],
        '*', '4', 'FETCH', ['UID', '102', 'RFC822.HEADER', 'Subject: Bar'],
        'TAG2', 'OK', 'FETCH', 'completed',
    ]);
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

    expect($parser->parse())->toEqual([
        '*', 'QUOTA', '#user/testuser', ['STORAGE', '512', '1024'],
        'TAG1', 'OK', 'GETQUOTA', 'completed',
    ]);
});
