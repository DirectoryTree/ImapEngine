<?php

use DirectoryTree\ImapEngine\Connection\CommandArgument;

test('literal returns a double-quoted escaped string when no newline is present', function () {
    expect(CommandArgument::literal('hello'))->toBe('"hello"');
    expect(CommandArgument::literal('He said: "Hi"'))->toBe('"He said: \\"Hi\\""');
});

test('charset uses atoms when possible and quotes other names', function () {
    expect(CommandArgument::charset('UTF-8'))->toBe('UTF-8');
    expect(CommandArgument::charset('US-ASCII'))->toBe('US-ASCII');
    expect(CommandArgument::charset('UTF "8"'))->toBe('"UTF \\"8\\""');
});

test('charset rejects control characters', function (string $charset) {
    expect(fn () => CommandArgument::charset($charset))->toThrow(InvalidArgumentException::class);
})->with(["UTF\0-8", "UTF\t-8", "UTF\r-8", "UTF\n-8", "UTF\x7f-8"]);

test('atom accepts valid IMAP atoms', function (string $atom) {
    expect(CommandArgument::atom($atom))->toBe($atom);
})->with(['QRESYNC', 'UTF8=ACCEPT', 'X-GOOD-IDEA']);

test('atom rejects invalid IMAP atoms', function (string $atom) {
    expect(fn () => CommandArgument::atom($atom))->toThrow(InvalidArgumentException::class);
})->with(['', 'BAD CAPABILITY', "BAD\r\nCAPABILITY", 'BAD]CAPABILITY', 'BAD\\CAPABILITY']);

test('mechanism accepts valid SASL mechanism names', function (string $mechanism) {
    expect(CommandArgument::mechanism($mechanism))->toBe($mechanism);
})->with(['PLAIN', 'XOAUTH2', 'X-CUSTOM_MECHANISM']);

test('mechanism rejects invalid SASL mechanism names', function (string $mechanism) {
    expect(fn () => CommandArgument::mechanism($mechanism))->toThrow(InvalidArgumentException::class);
})->with(['', 'plain', 'BAD MECHANISM', "BAD\r\nMECHANISM", str_repeat('A', 21)]);

test('literal preserves carriage returns and newlines using literals', function (string $input) {
    $expected = ['{'.strlen($input).'}', $input];
    expect(CommandArgument::literal($input))->toBe($expected);
})->with(["hello\nworld", "hello\rworld", "hello\r\nworld"]);

test('literal handles an array of literals', function () {
    expect(CommandArgument::literal(['first', 'second']))->toBe(['"first"', '"second"']);
});

test('list returns a properly formatted parenthesized list for a flat array', function () {
    expect(CommandArgument::list(['"a"', '"b"', '"c"']))->toBe('("a" "b" "c")');
});

test('list handles nested arrays recursively', function () {
    expect(CommandArgument::list(['"a"', ['"b"', '"c"']]))->toBe('("a" ("b" "c"))');
});

test('list returns empty parentheses for an empty array', function () {
    expect(CommandArgument::list([]))->toBe('()');
});
