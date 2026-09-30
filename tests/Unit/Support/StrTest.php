<?php

use DirectoryTree\ImapEngine\Support\Str;

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

test('fromImapUtf7 decodes UTF-7 encoded folder names', function () {
    // Russian Cyrillic example from the bug report.
    $encoded = '&BBoEPgRABDcEOAQ9BDA-';
    $decoded = 'Корзина';

    expect(Str::fromImapUtf7($encoded))->toBe($decoded);
});

test('fromImapUtf7 handles non-encoded strings', function () {
    $plainString = 'INBOX';

    expect(Str::fromImapUtf7($plainString))->toBe($plainString);
});

test('fromImapUtf7 handles special characters', function () {
    // Ampersand is represented as &- in UTF-7.
    $encoded = '&-';
    $decoded = '&';

    expect(Str::fromImapUtf7($encoded))->toBe($decoded);
});

test('fromImapUtf7 handles mixed content', function () {
    // Test that the function doesn't modify the non-encoded part.
    $encoded = 'Hello &-';
    $decoded = 'Hello &';

    expect(Str::fromImapUtf7($encoded))->toBe($decoded);
});

test('fromImapUtf7 preserves existing UTF-8 characters', function () {
    // Test with various UTF-8 characters that should remain unchanged.
    $utf8String = 'Привет мир 你好 こんにちは ñáéíóú';

    // The function should return the string unchanged since it's already UTF-8.
    expect(Str::fromImapUtf7($utf8String))->toBe($utf8String);

    // Test with a mix of UTF-8 and regular ASCII.
    $mixedString = 'Hello Привет 123';
    expect(Str::fromImapUtf7($mixedString))->toBe($mixedString);
});

test('toImapUtf7 encodes plain ASCII as-is', function () {
    $input = 'Inbox';
    $expected = 'Inbox';

    expect(Str::toImapUtf7($input))->toBe($expected);
});

test('toImapUtf7 encodes ampersand correctly', function () {
    $input = 'Inbox & Archive';
    $expected = 'Inbox &- Archive';

    expect(Str::toImapUtf7($input))->toBe($expected);
});

test('toImapUtf7 encodes non-ASCII characters', function () {
    $input = 'Корзина'; // Russian for "Trash"
    $expected = '&BBoEPgRABDcEOAQ9BDA-';

    expect(Str::toImapUtf7($input))->toBe($expected);
});

test('toImapUtf7 encodes mixed content correctly', function () {
    $input = 'Work Корзина & Stuff';
    $expected = 'Work &BBoEPgRABDcEOAQ9BDA- &- Stuff';

    expect(Str::toImapUtf7($input))->toBe($expected);
});
