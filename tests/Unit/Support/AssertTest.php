<?php

use DirectoryTree\ImapEngine\Support\Assert;

test('hasNoControlCharacters accepts command syntax', function (string $token) {
    expect(fn () => Assert::hasNoControlCharacters($token))->not->toThrow(InvalidArgumentException::class);
})->with(['TAG1', 'UID SEARCH', 'UID 1:5', '(\\Seen Important)', '{5}', '{5+}', '"INBOX"']);

test('hasNoControlCharacters rejects control characters', function (string $token) {
    expect(fn () => Assert::hasNoControlCharacters($token))->toThrow(InvalidArgumentException::class);
})->with([
    "UID 1\r\nX1 SELECT Secret", "foo\rbar", "foo\nbar", "foo\0bar", "foo\tbar", "foo\x7Fbar",
]);

test('isValidFlag accepts system flags and keywords', function (string $flag) {
    expect(fn () => Assert::isValidFlag($flag))->not->toThrow(InvalidArgumentException::class);
})->with(['\\Seen', '\\Draft', '\\Custom', 'Important', '$Junk']);

test('isValidFlag rejects invalid atoms', function (string $flag) {
    expect(fn () => Assert::isValidFlag($flag))->toThrow(InvalidArgumentException::class);
})->with([
    '', '\\', 'two flags', '(Seen)', '{5}', '%', '*', '"Seen"',
    'foo\\bar', '\\\\Seen', 'Seen]', "Seen\0", "Seen\t", "Seen\r", "Seen\n", "Seen\x7F", 'é',
    "Seen)\r\nX1 NOOP (",
]);

test('isValidSequenceSet accepts message numbers and ranges', function (string $set) {
    expect(fn () => Assert::isValidSequenceSet($set))->not->toThrow(InvalidArgumentException::class);
})->with(['1', '*', '1:*', '*:4', '9:3', '1,3:5,*']);

test('isValidSequenceSet rejects invalid sequence sets', function (string $set) {
    expect(fn () => Assert::isValidSequenceSet($set))->toThrow(InvalidArgumentException::class);
})->with([
    "1\r\nX1 NOOP", '1e2', '01', '1 ALL', '1,,2', '1:2:3', '', '0', '-1', '1.5',
]);
