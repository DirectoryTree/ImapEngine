<?php

use DirectoryTree\ImapEngine\Connection\FakeStream;
use DirectoryTree\ImapEngine\Connection\ImapParseException;
use DirectoryTree\ImapEngine\Connection\ImapStreamException;
use DirectoryTree\ImapEngine\Connection\ImapTokenizer;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Crlf;
use DirectoryTree\ImapEngine\Connection\Tokens\EmailAddress;
use DirectoryTree\ImapEngine\Connection\Tokens\ListClose;
use DirectoryTree\ImapEngine\Connection\Tokens\ListOpen;
use DirectoryTree\ImapEngine\Connection\Tokens\Literal;
use DirectoryTree\ImapEngine\Connection\Tokens\QuotedString;
use DirectoryTree\ImapEngine\Connection\Tokens\ResponseCodeClose;
use DirectoryTree\ImapEngine\Connection\Tokens\ResponseCodeOpen;
use DirectoryTree\ImapEngine\Connection\Tokens\Token;

test('tokenizer returns an atom token', function () {
    $stream = new FakeStream;
    $stream->open();

    // Feed a simple ATOM with trailing whitespace/newline.
    $stream->feed("ATOM1\r\n");

    $tokenizer = new ImapTokenizer($stream);

    $token = $tokenizer->nextToken();

    expect($token)->toBeInstanceOf(Atom::class);
    expect($token->value)->toBe('ATOM1');
});

test('tokenizer returns a quoted string token', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed('"Hello, world!"');

    $tokenizer = new ImapTokenizer($stream);

    $token = $tokenizer->nextToken();

    expect($token)->toBeInstanceOf(QuotedString::class);
    expect($token->value)->toBe('Hello, world!');
});

test('tokenizer returns an email address token', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed('<johndoe@email.com>');

    $tokenizer = new ImapTokenizer($stream);

    $token = $tokenizer->nextToken();

    expect($token)->toBeInstanceOf(EmailAddress::class);
    expect($token->value)->toBe('johndoe@email.com');
});

test('tokenizer returns a literal token', function () {
    $stream = new FakeStream;
    $stream->open();

    // Feed a literal block. Literal syntax is {<size>}\r\n followed by that many bytes.
    $stream->feed("{5}\r\nHello");

    $tokenizer = new ImapTokenizer($stream);
    $token = $tokenizer->nextToken();

    expect($token)->toBeInstanceOf(Literal::class);
    expect($token->value)->toHaveLength(5);
    expect($token->value)->toBe('Hello');
});

test('tokenizer returns list open and list close tokens', function () {
    $stream = new FakeStream;
    $stream->open();

    // Feed a response that contains list delimiters.
    // The stream contains two separate tokens: "(" and ")".
    $stream->feed(['(', ')']);

    $tokenizer = new ImapTokenizer($stream);

    $tokenOpen = $tokenizer->nextToken();
    expect($tokenOpen)->toBeInstanceOf(ListOpen::class);
    expect($tokenOpen->value)->toBe('(');

    $crlf = $tokenizer->nextToken();
    expect($crlf)->toBeInstanceOf(Crlf::class);
    expect($crlf->value)->toBe("\r\n");

    expect($tokenizer->nextToken())->toBeNull();

    $tokenClose = $tokenizer->nextToken();
    expect($tokenClose)->toBeInstanceOf(ListClose::class);
    expect($tokenClose->value)->toBe(')');
});

test('tokenizer handles escaped characters in quoted strings', function () {
    $stream = new FakeStream;
    $stream->open();

    // Feed a quoted string with escaped quotes.
    $stream->feed("\"Hello \\\"World\\\"!\"\r\n");

    $tokenizer = new ImapTokenizer($stream);
    $token = $tokenizer->nextToken();

    expect($token)->toBeInstanceOf(QuotedString::class);
    expect($token->value)->toBe('Hello "World"!');
});

test('tokenizer skips leading whitespace', function () {
    $stream = new FakeStream;

    $stream->open();

    // Feed data with leading whitespace before an ATOM.
    $stream->feed("   ATOM2\r\n");

    $tokenizer = new ImapTokenizer($stream);
    $token = $tokenizer->nextToken();

    expect($token)->toBeInstanceOf(Atom::class);
    expect($token->value)->toBe('ATOM2');
});

test('tokenizer returns a CRLF token', function () {
    $stream = new FakeStream;
    $stream->open();

    // Feed a CRLF token in isolation.
    // Here, the CRLF appears before further tokens.
    $stream->feed("\r\nATOM3\r\n");
    $tokenizer = new ImapTokenizer($stream);

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(Crlf::class);
    expect($token->value)->toBe("\r\n");
});

test('tokenizer returns a response code open token', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed('[');
    $tokenizer = new ImapTokenizer($stream);

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(ResponseCodeOpen::class);
    expect($token->value)->toBe('[');
});

test('tokenizer returns a response code close token', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed(']');
    $tokenizer = new ImapTokenizer($stream);

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(ResponseCodeClose::class);
    expect($token->value)->toBe(']');
});

test('tokenizer throws exception for CR not followed by LF', function () {
    $stream = new FakeStream;
    $stream->open();

    // Feed a string with a CR that is not immediately followed by LF.
    $stream->feedRaw("ATOM\r");
    $tokenizer = new ImapTokenizer($stream);

    expect($tokenizer->nextToken()->value)->toBe('ATOM');

    $tokenizer->nextToken();
})->throws(ImapParseException::class);

test('tokenizer throws exception for unterminated quoted string', function () {
    $stream = new FakeStream;
    $stream->open();

    // Feed an unterminated quoted string.
    $stream->feed('"Unterminated quoted string');
    $tokenizer = new ImapTokenizer($stream);

    $tokenizer->nextToken();
})->throws(ImapParseException::class, 'Unterminated quoted string at buffer offset 0. Buffer: ');

test('tokenizer throws exception for literal with incorrect size', function () {
    $stream = new FakeStream;
    $stream->open();

    // Specify literal size as 5 but provide only 2 bytes.
    $stream->feed([
        '{5}',
        'ab',
    ]);

    $tokenizer = new ImapTokenizer($stream);
    $tokenizer->nextToken();
})->throws(ImapStreamException::class);

test('tokenizer lets additional data be consumed as atoms', function () {
    $stream = new FakeStream;
    $stream->open();

    // Specify literal size as 5 but provide only 6 bytes.
    $stream->feed([
        '{5}',
        'abcdef',
    ]);

    $tokenizer = new ImapTokenizer($stream);

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(Literal::class);
    expect($token->value)->toHaveLength(5);
    expect($token->value)->toBe('abcde');

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(Atom::class);
    expect($token->value)->toBe('f');
});

test('tokenizer returns null with no feed', function () {
    $stream = new FakeStream;
    $stream->open();

    $tokenizer = new ImapTokenizer($stream);

    expect($tokenizer->nextToken())->toBeNull();
});

test('tokenizer parsed tagged response', function () {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed('TAG1 OK [UIDNEXT 1000] Completed');

    $tokenizer = new ImapTokenizer($stream);

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(Atom::class);
    expect($token->value)->toBe('TAG1');

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(Atom::class);
    expect($token->value)->toBe('OK');

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(ResponseCodeOpen::class);
    expect($token->value)->toBe('[');

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(Atom::class);
    expect($token->value)->toBe('UIDNEXT');

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(Atom::class);
    expect($token->value)->toBe('1000');

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(ResponseCodeClose::class);
    expect($token->value)->toBe(']');

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(Atom::class);
    expect($token->value)->toBe('Completed');

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(Crlf::class);
    expect($token->value)->toBe("\r\n");

    expect($tokenizer->nextToken())->toBeNull();
});

test('all tokens implement the token interface', function (string $data) {
    $stream = new FakeStream;
    $stream->open();

    $stream->feed($data);
    $tokenizer = new ImapTokenizer($stream);

    $token = $tokenizer->nextToken();
    expect($token)->toBeInstanceOf(Token::class);
})->with([
    'ATOM4', // Atom
    '(',  // ListOpen
    ')', // ListClose
    '[',  // ResponseCodeOpen
    ']',  // ResponseCodeClose
    "{3}\r\nFoo",  // Literal
    '"Bar"',  // QuotedString
]);
