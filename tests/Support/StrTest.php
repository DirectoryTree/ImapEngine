<?php

use DirectoryTree\ImapEngine\Support\Str;

test('set', function () {
    expect(Str::set(5, 10))->toBe('5:10');
    expect(Str::set(5, INF))->toBe('5:*');
    expect(Str::set([5, 10]))->toBe('5,10');
    expect(Str::set([5]))->toBe('5:5');
    expect(Str::set(5))->toBe('5');
});

test('escape removes newlines/control characters and escapes backslashes and double quotes', function () {
    // Newlines and control characters removed
    expect(Str::escape("Hello\nWorld"))->toBe('HelloWorld');
    expect(Str::escape("Hello\tWorld"))->toBe('HelloWorld'); // Tab (ASCII 9) removed

    // Double quotes are escaped
    expect(Str::escape('He said: "Hi"'))->toBe('He said: \\"Hi\\"');

    // Backslashes are escaped
    // Input: C:\Path\to\file becomes: C:\\Path\\to\\file (each '\' becomes '\\')
    expect(Str::escape('C:\Path\to\file'))->toBe('C:\\\\Path\\\\to\\\\file');
});

test('literal returns a double-quoted escaped string when no newline is present', function () {
    expect(Str::literal('hello'))->toBe('"hello"');
    expect(Str::literal('He said: "Hi"'))->toBe('"He said: \\"Hi\\""');
});

test('literal returns a literal indicator and the original string if it contains a newline', function () {
    $input = "hello\nworld";
    $expected = ['{'.strlen($input).'}', $input];
    expect(Str::literal($input))->toBe($expected);
});

test('literal handles multiple arguments by returning an array of literals', function () {
    expect(Str::literal('first', 'second'))
        ->toBe([
            '"first"',
            '"second"',
        ]);
});

test('list returns a properly formatted parenthesized list for a flat array', function () {
    expect(Str::list(['"a"', '"b"', '"c"']))->toBe('("a" "b" "c")');
});

test('list handles nested arrays recursively', function () {
    expect(Str::list(['"a"', ['"b"', '"c"']]))->toBe('("a" ("b" "c"))');
});

test('list returns empty parentheses for an empty array', function () {
    expect(Str::list([]))->toBe('()');
});
