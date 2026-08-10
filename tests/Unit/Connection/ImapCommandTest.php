<?php

use DirectoryTree\ImapEngine\Connection\ImapCommand;
use DirectoryTree\ImapEngine\Connection\ImapCommandLine;

test('compile returns correct command lines for no tokens', function () {
    $cmd = new ImapCommand('A001', 'NOOP');

    $lines = $cmd->compile();

    expect($lines)->toHaveCount(1);
    expect($lines[0])->toBeInstanceOf(ImapCommandLine::class);
    expect((string) $lines[0])->toBe('A001 NOOP');
    expect($lines[0]->synchronizing)->toBeFalse();
});

test('compile returns correct command lines for string tokens', function () {
    $cmd = new ImapCommand('A002', 'LOGIN', ['user', 'pass']);

    $lines = $cmd->compile();

    expect($lines)->toHaveCount(1);
    expect((string) $lines[0])->toBe('A002 LOGIN user pass');
});

test('redacted returns command lines with tokens redacted for safety', function () {
    $cmd = new ImapCommand('A002', 'LOGIN', ['user', 'pass']);

    $lines = $cmd->redacted()->compile();

    expect($lines)->toHaveCount(1);
    expect((string) $lines[0])->toBe('A002 LOGIN [redacted] [redacted]');
});

test('compile returns correct command lines with a literal token', function () {
    $cmd = new ImapCommand('A003', 'APPEND "INBOX"', [
        ['{20}', 'literal-data'],
    ]);

    $lines = $cmd->compile();

    expect($lines)->toHaveCount(2);
    expect((string) $lines[0])->toBe('A003 APPEND "INBOX" {20}');
    expect($lines[0]->synchronizing)->toBeTrue();
    expect((string) $lines[1])->toBe('literal-data');
    expect($lines[1]->synchronizing)->toBeFalse();
});

test('compile marks non-synchronizing literal command lines', function () {
    $cmd = new ImapCommand('A003', 'APPEND "INBOX"', [
        ['{20+}', 'literal-data'],
    ]);

    $lines = $cmd->compile();

    expect($lines)->toHaveCount(2);
    expect((string) $lines[0])->toBe('A003 APPEND "INBOX" {20+}');
    expect($lines[0]->synchronizing)->toBeFalse();
    expect((string) $lines[1])->toBe('literal-data');
});

test('compile returns correct command lines with multiple tokens including a literal', function () {
    $cmd = new ImapCommand('A004', 'COMMAND', [
        'TOKEN1',
        ['{5}', 'LIT'],
        'TOKEN2',
    ]);

    $lines = $cmd->compile();

    expect($lines)->toHaveCount(2);
    expect((string) $lines[0])->toBe('A004 COMMAND TOKEN1 {5}');
    expect($lines[0]->synchronizing)->toBeTrue();
    expect((string) $lines[1])->toBe('LIT TOKEN2');
});

test('compile tracks synchronization for each literal command line', function () {
    $cmd = new ImapCommand('A004', 'COMMAND', [
        ['{5+}', 'FIRST'],
        ['{6}', 'SECOND'],
    ]);

    $lines = $cmd->compile();

    expect($lines)->toHaveCount(3);
    expect((string) $lines[0])->toBe('A004 COMMAND {5+}');
    expect($lines[0]->synchronizing)->toBeFalse();
    expect((string) $lines[1])->toBe('FIRST {6}');
    expect($lines[1]->synchronizing)->toBeTrue();
    expect((string) $lines[2])->toBe('SECOND');
    expect($lines[2]->synchronizing)->toBeFalse();
});

test('to string returns the command lines joined by CRLF', function () {
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
