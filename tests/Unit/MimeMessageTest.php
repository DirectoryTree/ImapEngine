<?php

use DirectoryTree\ImapEngine\BodyStructurePart;
use DirectoryTree\ImapEngine\Support\MimeMessage;

test('it builds mime message with content type', function () {
    $part = new BodyStructurePart(
        partNumber: '1',
        type: 'text',
        subtype: 'plain',
    );

    $mime = MimeMessage::make($part, 'Hello World!');

    expect($mime)->toBe("Content-Type: text/plain\r\n\r\nHello World!");
});

test('it builds mime message with charset', function () {
    $part = new BodyStructurePart(
        partNumber: '1',
        type: 'text',
        subtype: 'plain',
        parameters: ['charset' => 'utf-8'],
    );

    $mime = MimeMessage::make($part, 'Hello World!');

    expect($mime)->toBe("Content-Type: text/plain; charset=\"utf-8\"\r\n\r\nHello World!");
});

test('it builds mime message with transfer encoding', function () {
    $part = new BodyStructurePart(
        partNumber: '1',
        type: 'text',
        subtype: 'plain',
        encoding: 'base64',
    );

    $mime = MimeMessage::make($part, 'SGVsbG8gV29ybGQh');

    expect($mime)->toBe("Content-Type: text/plain\r\nContent-Transfer-Encoding: base64\r\n\r\nSGVsbG8gV29ybGQh");
});

test('it builds mime message with charset and transfer encoding', function () {
    $part = new BodyStructurePart(
        partNumber: '1',
        type: 'text',
        subtype: 'plain',
        parameters: ['charset' => 'utf-8'],
        encoding: 'quoted-printable',
    );

    $mime = MimeMessage::make($part, 'Hello=20World!');

    expect($mime)->toBe("Content-Type: text/plain; charset=\"utf-8\"\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nHello=20World!");
});

test('it builds mime message for html content', function () {
    $part = new BodyStructurePart(
        partNumber: '1',
        type: 'text',
        subtype: 'html',
        parameters: ['charset' => 'utf-8'],
        encoding: '7bit',
    );

    $mime = MimeMessage::make($part, '<p>Hello World!</p>');

    expect($mime)->toBe("Content-Type: text/html; charset=\"utf-8\"\r\nContent-Transfer-Encoding: 7bit\r\n\r\n<p>Hello World!</p>");
});

test('it builds mime message with iso-8859-1 charset', function () {
    $part = new BodyStructurePart(
        partNumber: '1',
        type: 'text',
        subtype: 'plain',
        parameters: ['charset' => 'iso-8859-1'],
        encoding: 'quoted-printable',
    );

    $mime = MimeMessage::make($part, 'Caf=E9');

    expect($mime)->toBe("Content-Type: text/plain; charset=\"iso-8859-1\"\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nCaf=E9");
});
