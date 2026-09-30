<?php

use DirectoryTree\ImapEngine\Connection\CommandPart;

test('it can be converted to a string', function (string|int $value, string $expected) {
    expect((string) new CommandPart($value))->toBe($expected);
})->with([
    'string' => ['UID FETCH', 'UID FETCH'],
    'integer' => [42, '42'],
]);

test('it rejects control characters', function (string $value) {
    expect(fn () => new CommandPart($value))
        ->toThrow(InvalidArgumentException::class);
})->with([
    "TAG1\r\nTAG2 LOGOUT",
    "TAG1\0TAG2",
    "TAG1\tTAG2",
    "TAG1\x7FTAG2",
]);
