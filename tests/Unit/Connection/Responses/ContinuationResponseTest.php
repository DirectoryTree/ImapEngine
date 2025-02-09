<?php

use DirectoryTree\ImapEngine\Connection\Responses\ContinuationResponse;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

test('data', function () {
    $response = new ContinuationResponse([
        new Atom('a'),
        new Atom('b'),
        new Atom('c'),
    ]);

    expect($response->data())->toEqual([
        new Atom('b'),
        new Atom('c'),
    ]);
});
