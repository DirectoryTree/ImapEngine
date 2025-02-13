<?php

use DirectoryTree\ImapEngine\Connection\Responses\Data\ResponseCodeData;
use DirectoryTree\ImapEngine\Connection\Tokens\Atom;

test('to string', function () {
    $response = new ResponseCodeData([
        new Atom('a'),
        new Atom('b'),
        new Atom('c'),
    ]);

    expect((string) $response)->toEqual('[a b c]');
});
