<?php

use DirectoryTree\ImapEngine\AppendResult;
use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Responses\TaggedResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

test('it creates a result from an APPENDUID response', function () {
    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('OK'),
        new ResponseCodeData([
            new Atom('APPENDUID'),
            new Atom('1234567890'),
            new Atom('42'),
        ]),
        new Atom('APPEND completed'),
    ]);

    $result = AppendResult::fromResponse($response);

    expect($result->uidValidity())->toBe(1234567890)
        ->and($result->uid())->toBe(42);
});

test('it creates a result without identifiers when APPENDUID is unavailable', function () {
    $response = new TaggedResponse([
        new Atom('TAG1'),
        new Atom('OK'),
        new Atom('APPEND completed'),
    ]);

    $result = AppendResult::fromResponse($response);

    expect($result->uidValidity())->toBeNull()
        ->and($result->uid())->toBeNull();
});
