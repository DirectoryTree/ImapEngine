<?php

use DirectoryTree\ImapEngine\Support\MimeParameterParser;

test('it parses regular parameters', function () {
    $parameters = MimeParameterParser::parse([
        'charset' => 'utf-8',
        'name' => 'document.pdf',
    ]);

    expect($parameters)->toBe([
        'charset' => 'utf-8',
        'name' => 'document.pdf',
    ]);
});

test('it combines RFC 2231 parameter continuations in numerical order', function () {
    $parameters = MimeParameterParser::parse([
        'filename*1' => 'attachment_name_part_1.pdf',
        'filename*0' => 'attachment_name_part_0',
    ]);

    expect($parameters)->toBe([
        'filename' => 'attachment_name_part_0attachment_name_part_1.pdf',
    ]);
});

test('it decodes RFC 2231 extended parameter continuations', function () {
    $parameters = MimeParameterParser::parse([
        'filename*1*' => '2026.pdf',
        'filename*0*' => "utf-8''invoice%20",
    ]);

    expect($parameters)->toBe([
        'filename' => 'invoice 2026.pdf',
    ]);
});

test('it decodes standalone RFC 2231 extended parameters', function () {
    $parameters = MimeParameterParser::parse([
        'filename*' => "utf-8''invoice%202026.pdf",
    ]);

    expect($parameters)->toBe([
        'filename' => 'invoice 2026.pdf',
    ]);
});

test('it converts RFC 2231 parameter values to UTF-8', function () {
    $parameters = MimeParameterParser::parse([
        'filename*' => "iso-8859-1''f%FCr%20Zustellungen.pdf",
    ]);

    expect($parameters)->toBe([
        'filename' => 'für Zustellungen.pdf',
    ]);
});

test('it decodes MIME encoded parameter values for compatibility', function () {
    $parameters = MimeParameterParser::parse([
        'filename' => '=?iso-8859-1?Q?f=FCr_Zustellungen.pdf?=',
    ]);

    expect($parameters)->toBe([
        'filename' => 'für Zustellungen.pdf',
    ]);
});

test('it preserves MIME quoted-string characters', function () {
    $parameters = MimeParameterParser::parse([
        'filename' => 'invoice "draft"\\document.pdf',
    ]);

    expect($parameters)->toBe([
        'filename' => 'invoice "draft"\\document.pdf',
    ]);
});
