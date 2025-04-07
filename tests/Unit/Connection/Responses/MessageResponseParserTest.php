<?php

use DirectoryTree\ImapEngine\Connection\Responses\Data\ListData;
use DirectoryTree\ImapEngine\Connection\Responses\MessageResponseParser;
use DirectoryTree\ImapEngine\Connection\Responses\UntaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;
use DirectoryTree\ImapEngine\Connection\Tokens\Literal;

it('parses flags from untagged response', function () {
    $response = new UntaggedResponse([
        new Atom('*'),      // Untagged marker
        new Atom('1'),      // Sequence number (example)
        new Atom('FETCH'),  // Command type
        new ListData([      // Data list
            new Atom('UID'),
            new Atom('12345'), // The message UID
            new Atom('FLAGS'),
            new ListData([      // The list of flags
                new Atom('\Seen'),
                new Atom('\Answered'),
                new Atom('$Important'), // Example custom flag
            ]),
        ]),
    ]);

    $parsedFlags = MessageResponseParser::getFlags($response);

    expect($parsedFlags)->toBe([
        '12345' => ['\Seen', '\Answered', '$Important'],
    ]);
});

it('parses body header from untagged response', function () {
    $headerContent = "From: sender@example.com\r\nTo: recipient@example.com\r\nSubject: Test Email Header\r\n";

    $response = new UntaggedResponse([
        new Atom('*'),      // Untagged marker
        new Atom('2'),      // Sequence number (example)
        new Atom('FETCH'),  // Command type
        new ListData([      // Data list
            new Atom('UID'),
            new Atom('54321'), // The message UID
            new Atom('BODY'),
            new Atom('[HEADER]'), // Specifies header part
            new Literal($headerContent), // The header content as a literal
        ]),
    ]);

    $parsedHeader = MessageResponseParser::getBodyHeader($response); //

    expect($parsedHeader)->toBe(['54321' => $headerContent]);
});

it('parses body text from untagged response', function () {
    $textContent = "This is the plain text body of the email.\r\nIt might have multiple lines.\r\n";

    $response = new UntaggedResponse([
        new Atom('*'),      // Untagged marker
        new Atom('3'),      // Sequence number (example)
        new Atom('FETCH'),  // Command type
        new ListData([      // Data list
            new Atom('UID'),
            new Atom('98765'), // The message UID
            new Atom('BODY'),
            new Atom('[TEXT]'),  // Specifies text part
            new Literal($textContent), // The text content as a literal
        ]),
    ]);

    $parsedText = MessageResponseParser::getBodyText($response);

    expect($parsedText)->toBe(['98765' => $textContent]);
});

it('handles empty flags list correctly', function () {
    $response = new UntaggedResponse([
        new Atom('*'),
        new Atom('4'),
        new Atom('FETCH'),
        new ListData([
            new Atom('UID'),
            new Atom('11111'),
            new Atom('FLAGS'),
            new ListData([]), // Empty flags list
        ]),
    ]);

    $parsedFlags = MessageResponseParser::getFlags($response);

    expect($parsedFlags)->toBe(['11111' => []]);
});
