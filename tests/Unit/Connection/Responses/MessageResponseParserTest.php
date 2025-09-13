<?php

use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\MessageResponseParser;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

test('it parses UID from tagged COPYUID response', function () {
    $response = new TaggedResponse([
        new Atom('TAG1'),             // Tag
        new Atom('OK'),               // Status
        new ResponseCodeData([
            new Atom('COPYUID'),      // Response code
            new Atom('1570950167'),   // UIDVALIDITY
            new Atom('1234'),         // Source UID
            new Atom('5678'),         // Destination UID
        ]),
        new Atom('Move completed.'),  // Human-readable text
    ]);

    $parsedUid = MessageResponseParser::getUidFromCopy($response);

    expect($parsedUid)->toBe(5678);
});

test('it returns null for non-COPYUID tagged response', function () {
    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('OK'),
        new Atom('Move completed.'),
    ]);

    expect(MessageResponseParser::getUidFromCopy($response))->toBeNull();
});
