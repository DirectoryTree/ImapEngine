<?php

use DirectoryTree\ImapEngine\Connection\ImapCommand;

test('compile returns correct command lines for no tokens', function () {
    $cmd = new ImapCommand('A001', 'NOOP');

    expect($cmd->compile())->toEqual(['A001 NOOP']);
});

test('compile returns correct command lines for string tokens', function () {
    $cmd = new ImapCommand('A002', 'LOGIN', ['user', 'pass']);

    expect($cmd->compile())->toEqual(['A002 LOGIN user pass']);
});

test('compile returns correct command lines with a literal token', function () {
    $cmd = new ImapCommand('A003', 'APPEND "INBOX"', [
        ['{20}', 'literal-data'],
    ]);

    expect($cmd->compile())->toEqual(['A003 APPEND "INBOX" {20}', 'literal-data']);
});

test('compile returns correct command lines with multiple tokens including a literal', function () {
    $cmd = new ImapCommand('A004', 'COMMAND', [
        'TOKEN1',
        ['{5}', 'LIT'],
        'TOKEN2',
    ]);

    expect($cmd->compile())->toEqual([
        'A004 COMMAND TOKEN1 {5}',
        'LIT TOKEN2',
    ]);
});

test('__toString returns the command lines joined by CRLF', function () {
    $cmd = new ImapCommand('A003', 'APPEND "INBOX"', [
        ['{20}', 'literal-data'],
    ]);

    $expected = "A003 APPEND \"INBOX\" {20}\r\nliteral-data";

    expect((string) $cmd)->toBe($expected);
});

test('compile caches result on subsequent calls', function () {
    $cmd = new ImapCommand('A005', 'NOOP');

    $firstCall = $cmd->compile();
    $secondCall = $cmd->compile();

    expect($firstCall)->toBe($secondCall);
});
